<?php

namespace App\Http\Controllers;
use App\Models\Product;
use App\Models\Webhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Laravel\Telescope\Telescope;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use App\Jobs\SyncProductToStore2;
use App\Jobs\SyncProductToStore3;
use App\Jobs\SyncProductUpdateToStore2;
use App\Jobs\SyncProductUpdateToStore3;

class WebhookController extends Controller
{
    //
    protected $client;
    protected $shopUrl;
    protected $accessToken;

    public function __construct()
    {
        $this->client = new Client();
        $this->shopUrl = env('SHOPIFY_SHOP_EILUMINAT_URL');
        $this->accessToken = env('ACCESS_TOKEN_ADMIN_EILUMINAT');
    }

    public function index()
    {
        return view('dashboard');
    }

    public function showLogs()
    {
        $webhooks = Webhook::paginate(100);
        return view('webhooks', compact('webhooks'));
    }
    
    public function show($id)
    {
        $webhook = Webhook::findOrFail($id);
        return response()->json($webhook->payload);
    }

    /* product create webhook validate */
    public function webhookProductCreate(Request $request){
        Log::info('🟢 webhookProductCreate received');
        function validateWebhook($data, $hmacHeader, $sharedSecret) {
            $calculatedHmac = base64_encode(hash_hmac('sha256', $data, $sharedSecret, true));
            return hash_equals($calculatedHmac, $hmacHeader);
        }

        // Read required headers and raw body exactly as received
        $hmacHeader  = $request->header('X-Shopify-Hmac-Sha256');
        $header_topic = $request->header('x-shopify-topic');
        $header_id = $request->header('x-shopify-webhook-id');
        $product_id_webhook = $request->header('x-shopify-product-id');
        $header_date = $request->header('x-shopify-triggered-at');
        $data = $request->getContent();

        // Load from config (env() not reliable with config cache)
        $sharedSecret = config('shopify.webhook_secret');

        if (!$sharedSecret) {
            Log::error('❌ Webhook secret missing. Ensure API_KEY_SECRET_EILUMINAT or SHOPIFY_WEBHOOK_SECRET exists in .env and config cache is rebuilt.', [
                'config_cached' => app()->configurationIsCached(),
                'config_value_present' => false,
            ]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        Log::info('🔐 Webhook secret loaded from config', [
            'len' => strlen($sharedSecret),
            'config_cached' => app()->configurationIsCached(),
        ]);

        $isValid = $hmacHeader && validateWebhook($data, $hmacHeader, $sharedSecret);

        if ($isValid) {
                //Log::info("Shopify webhook working");
                $payload = json_decode($data, true);
                Webhook::create([
                    'topic' => $header_topic,
                    'webhook_id' => $header_id,
                    'product_id' => $payload['id'] ?? null, 
                    'triggered_at' => Carbon::parse($header_date),
                    'payload' => $payload,
                ]);
                //Log::info('Webhook Data: ', $payload);
                // Log::info('Shopify Webhook Data:', [
                //     'HMAC' => $request->header('X-Shopify-Hmac-Sha256'),
                //     'Topic' => $request->header('x-shopify-topic'),
                //     'Webhook ID' => $request->header('x-shopify-webhook-id'),
                //     'Product ID' => $request->header('x-shopify-product-id'),
                //     'Triggered At' => $request->header('x-shopify-triggered-at'),
                // ]);
                //Log::info('Webhook Data LOGS: ' . $payload['admin_graphql_api_id']);
                
                //$this->getProductById($payload['admin_graphql_api_id']);
                // $this->cloneProductToOtherStores($payload);
                

                Log::debug('🔍 Variants:', $payload['variants'] ?? []);
                Log::debug('🖼️ Images:', $payload['images'] ?? []);
                Log::info('🧾 Product summary', [
                    'id' => $payload['id'] ?? null,
                    'title' => $payload['title'] ?? null,
                    'handle' => $payload['handle'] ?? null,
                    'variants_count' => isset($payload['variants']) ? count($payload['variants']) : 0,
                    'images_count' => isset($payload['images']) ? count($payload['images']) : 0,
                ]);

                SyncProductToStore2::dispatch($payload)->delay(now()->addSeconds(10));
                SyncProductToStore3::dispatch($payload)->delay(now()->addSeconds(10));
                Log::warning('🚫 Sync dispatch to Store2/Store3 is disabled (dispatch lines commented). No product will be created in other stores.');

        } else {
            // Safe debug: log only prefixes to avoid leaking secrets
            $calc = base64_encode(hash_hmac('sha256', $data, $sharedSecret, true));
            Log::error('Webhook INVALID! HMAC mismatch.', [
                'header_present' => (bool) $hmacHeader,
                'header_prefix' => $hmacHeader ? substr($hmacHeader, 0, 8) : null,
                'calc_prefix' => substr($calc, 0, 8),
                'topic' => $header_topic,
                'webhook_id' => $header_id,
            ]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }
    }

    public function webhookProductUpdate(Request $request)
    {
        // Read and validate the webhook data
        $data = file_get_contents('php://input');
        $hmacHeader = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? ''; 
        $sharedSecret = env('API_KEY_SECRET_EILUMINAT');

        if (!$this->validateWebhook($data, $hmacHeader, $sharedSecret)) {
            Log::warning("⚠️ Webhook validation failed.");
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        Log::info("✅ Shopify webhook validated.");
        $shopifyData = json_decode($data, true);
        //Log::info("🔍 Full Shopify Webhook Payload:", $shopifyData);

        $productIdStore1 = $shopifyData['admin_graphql_api_id'] ?? null;
        if (!$productIdStore1) {
            Log::warning("⚠️ No product ID found in webhook payload.");
            return response()->json(['message' => 'No product ID found'], 400);
        }

        // Step 1: Prevent infinite update loops by checking the metafield
        $triggerValue = null;
        if (!empty($shopifyData['metafields'])) {
            foreach ($shopifyData['metafields'] as $metafield) {
                if ($metafield['namespace'] === 'custom' && $metafield['key'] === 'trigger') {
                    $triggerValue = $metafield['value'];
                    break;
                }
            }
        }

        if ($triggerValue === false || is_null($triggerValue)) {
            //Log::info("⏭️ Skipping update as 'custom.trigger' is not set to true.");
            return response()->json(['message' => 'No update required'], 200);
        }

        // Step 2: Update metafield to prevent duplicate updates
        $this->updateMetafieldTrigger($productIdStore1, "false");

        // Prepare product details (basic information)
        $productDetails = [
            'id' => $shopifyData['id'] ?? null, 
            'handle' => $shopifyData['handle'], 
            'admin_graphql_api_id' => $shopifyData['admin_graphql_api_id'] ?? 'N/A',
            'title' => $shopifyData['title'] ?? 'N/A',
            'body_html' => $shopifyData['body_html'] ?? 'N/A',
            'product_type' => $shopifyData['product_type'] ?? 'N/A',
            'status' => $shopifyData['status'] ?? 'N/A',
            'vendor' => $shopifyData['vendor'] ?? 'N/A',
            'tags' => isset($shopifyData['tags']) ? array_map('trim', explode(',', $shopifyData['tags'])) : [],
        ];

        $variants = $shopifyData['variants'] ?? [];
        $store1Images = array_map(fn($img) => $img['src'], $shopifyData['images'] ?? []);

        if (empty($variants)) {
            // This is a simple product (no variants)
            //Log::info("This product is simple (no variants). Updating product in Store 2.");
            
            // Dispatch job to update simple product (without variants) for store 2
            SyncProductUpdateToStore2::dispatch($productDetails, [], $store1Images, $shopifyData['options'] ?? [])
            ->delay(now()->addSeconds(3));
            SyncProductUpdateToStore3::dispatch($productDetails, [], $store1Images, $shopifyData['options'] ?? [])
            ->delay(now()->addSeconds(6));

            return response()->json(['message' => 'Webhook processed successfully']);
        }

        // Product with variants - continue processing variants
        //Log::info("This product has variants. Proceeding with variants update.");
        
        // Log::info("📦 Full Variant Payload from Store 1:", collect($variants)->map(function ($variant) {
        //     return [
        //         'sku' => $variant['sku'] ?? null,
        //         'price' => $variant['price'] ?? null,
        //         'compare_at_price' => $variant['compare_at_price'] ?? null,
        //         'barcode' => $variant['barcode'] ?? null,
        //         'inventory_policy' => $variant['inventory_policy'] ?? null,
        //         'taxable' => $variant['taxable'] ?? null,
        //         'option1' => $variant['option1'] ?? null,
        //         'option2' => $variant['option2'] ?? null,
        //         'option3' => $variant['option3'] ?? null,
        //         'title' => $variant['title'] ?? null,
        //         'id' => $variant['id'] ?? null,
        //     ];
        // })->toArray());
        // Dispatch job to update the product in store 2 with variants
        SyncProductUpdateToStore2::dispatch($productDetails, $variants, $store1Images, $shopifyData['options'] ?? [])
            ->delay(now()->addSeconds(3));

        SyncProductUpdateToStore3::dispatch($productDetails, $variants, $store1Images, $shopifyData['options'] ?? [])
        ->delay(now()->addSeconds(6));



        return response()->json(['message' => 'Webhook processed successfully']);
    }

    public function deleteShopifyWebhook($webhookId)
    {
        $storeUrl = env('SHOPIFY_SHOP_EILUMINAT_URL'); // Example: "your-store.myshopify.com"
        $accessToken = env('ACCESS_TOKEN_ADMIN_EILUMINAT');

        // REST (comentat):
        // $url = "https://$storeUrl/admin/api/2024-01/webhooks/{$webhookId}.json";
        // $response = Http::withHeaders([
        //     'X-Shopify-Access-Token' => $accessToken,
        //     'Content-Type' => 'application/json',
        // ])->delete($url);
        // if ($response->successful()) {
        //     Log::info("✅ Webhook {$webhookId} deleted successfully.");
        //     return response()->json(['message' => "Webhook {$webhookId} deleted successfully"], 200);
        // } else {
        //     Log::error("❌ Failed to delete webhook {$webhookId}: ", $response->json());
        //     return response()->json(['error' => "Failed to delete webhook", 'details' => $response->json()], $response->status());
        // }

        // ✅ GraphQL (echivalent): webhookSubscriptionDelete
        $graphqlUrl = "https://$storeUrl/admin/api/2025-01/graphql.json";
        $mutation = <<<'GRAPHQL'
        mutation DeleteWebhook($id: ID!) {
            webhookSubscriptionDelete(id: $id) {
                deletedWebhookSubscriptionId
                userErrors { field message }
            }
        }
        GRAPHQL;

        $variables = [
            'id' => "gid://shopify/WebhookSubscription/{$webhookId}"
        ];

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $accessToken,
            'Content-Type' => 'application/json',
        ])->post($graphqlUrl, [
            'query' => $mutation,
            'variables' => $variables
        ]);

        $body = $response->json();
        $deleted = $body['data']['webhookSubscriptionDelete']['deletedWebhookSubscriptionId'] ?? null;
        $errors = $body['data']['webhookSubscriptionDelete']['userErrors'] ?? [];

        if ($response->successful() && $deleted) {
            Log::info("✅ Webhook {$webhookId} deleted successfully via GraphQL.");
            return response()->json(['message' => "Webhook {$webhookId} deleted successfully"], 200);
        }

        Log::error("❌ Failed to delete webhook {$webhookId}", ['errors' => $errors, 'body' => $body]);
        return response()->json(['error' => 'Failed to delete webhook', 'details' => $body], 500);
    }

    public function getAllWebhooks()
    {
        $shopUrl = env('SHOPIFY_SHOP_EILUMINAT_URL');
        $accessToken = env('ACCESS_TOKEN_ADMIN_EILUMINAT');

        try {
            // REST (comentat):
            // $response = Http::withHeaders([
            //     'Content-Type' => 'application/json',
            //     'X-Shopify-Access-Token' => $accessToken,
            // ])->get("https://$shopUrl/admin/api/2025-01/webhooks.json");
            // $body = $response->json();

            // ✅ GraphQL (echivalent): webhookSubscriptions
            $graphqlUrl = "https://$shopUrl/admin/api/2025-01/graphql.json";
            $query = <<<'GRAPHQL'
            query ListWebhooks {
                webhookSubscriptions(first: 250) {
                    edges {
                        node {
                            id
                            topic
                            createdAt
                            format
                            endpoint {
                                __typename
                                ... on WebhookHttpEndpoint { callbackUrl }
                            }
                        }
                    }
                }
            }
            GRAPHQL;

            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json'
            ])->post($graphqlUrl, [
                'query' => $query
            ]);

            $body = $response->json();
            if (!$response->successful()) {
                Log::error("🚨 Failed to retrieve webhooks (GraphQL): ", $body);
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to retrieve webhooks.',
                    'error' => $body
                ], $response->status());
            }

            $edges = $body['data']['webhookSubscriptions']['edges'] ?? [];
            $webhooks = array_map(function($edge) {
                $n = $edge['node'];
                $delivery = $n['endpoint']['__typename'] ?? null;
                return [
                    'id' => (int) str_replace('gid://shopify/WebhookSubscription/', '', $n['id']),
                    'gid' => $n['id'],
                    'topic' => $n['topic'],
                    'created_at' => $n['createdAt'] ?? null,
                    'format' => $n['format'] ?? null,
                    'delivery_method' => $delivery,
                    'callback_url' => $delivery === 'WebhookHttpEndpoint' ? ($n['endpoint']['callbackUrl'] ?? null) : null,
                ];
            }, $edges);

            Log::info("✅ Retrieved Shopify Webhooks (GraphQL)", $webhooks);

            return response()->json([
                'success' => true,
                'webhooks' => $webhooks,
                'raw' => $body,
            ]);

        } catch (\Exception $e) {
            Log::error("🚨 Shopify API Exception: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve webhooks.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function createWebhook()
    {
        $shopifyStore = env('SHOPIFY_SHOP_EILUMINAT_URL');
        $accessToken = env('ACCESS_TOKEN_ADMIN_EILUMINAT');

        if (!$shopifyStore || !$accessToken) {
            Log::error('Missing Shopify credentials in .env file.');
            return response()->json(['error' => 'Shopify credentials are missing'], 500);
        }

        $client = new Client();
        $url = "https://$shopifyStore/admin/api/2024-07/graphql.json";

        $query = <<<'GRAPHQL'
        mutation webhookSubscriptionCreate(
          $topic: WebhookSubscriptionTopic!,
          $webhookSubscription: WebhookSubscriptionInput!
        ) {
          webhookSubscriptionCreate(
            topic: $topic,
            webhookSubscription: $webhookSubscription
          ) {
            webhookSubscription {
              id
              endpoint {
                __typename
                ... on WebhookHttpEndpoint {
                  callbackUrl
                }
              }
              format
            }
            userErrors {
              field
              message
            }
          }
        }
        GRAPHQL;

        $variables = [
            "topic" => "PRODUCTS_UPDATE",
            "webhookSubscription" => [
                "callbackUrl" => "https://coolify.lustreled.ro/app/product-update",
                "format" => "JSON",
                "metafieldNamespaces" => ["custom"],
                "filter" => "metafields.namespace:custom AND metafields.key:trigger AND metafields.value:true"
            ]
        ];

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'X-Shopify-Access-Token' => $accessToken
                ],
                'json' => [
                    'query' => $query,
                    'variables' => $variables
                ]
            ]);

            $body = json_decode($response->getBody(), true);

            // Log response
            Log::info('Shopify Webhook Response:', $body);

            return response()->json($body);
        } catch (\Exception $e) {
            Log::error('Shopify Webhook Error: ' . $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function validateWebhook($data, $hmacHeader, $sharedSecret)
    {
        $calculatedHmac = base64_encode(hash_hmac('sha256', $data, $sharedSecret, true));
        return hash_equals($calculatedHmac, $hmacHeader);
    }

    public function updateMetafieldTrigger($productIdStore1)
    {
        $shopUrl = env('SHOPIFY_SHOP_EILUMINAT_URL');
        $accessToken = env('ACCESS_TOKEN_ADMIN_EILUMINAT');

        // GraphQL Mutation
        $mutation = <<<'GRAPHQL'
        mutation productUpdate($input: ProductInput!) {
            productUpdate(input: $input) {
                product {
                    id
                    metafield(namespace: "custom", key: "trigger") {
                        id
                        namespace
                        key
                        value
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        // Variables for the mutation
        $variables = [
            'input' => [
                'id' => $productIdStore1,
                'metafields' => [
                    [
                        'namespace' => 'custom',
                        'key' => 'trigger',
                        'value' => 'false',  // Set to false
                        'type' => 'boolean'
                    ]
                ]
            ]
        ];

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Shopify-Access-Token' => $accessToken,
            ])->post("https://$shopUrl/admin/api/2025-01/graphql.json", [
                'query' => $mutation,
                'variables' => $variables
            ]);

            $body = $response->json();

            Log::info("🔄 Metafield Update Response: ", $body);

            // Check for errors
            if (isset($body['errors']) || !empty($body['data']['productUpdate']['userErrors'])) {
                Log::error("🚨 Metafield Update Failed: ", $body);
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to update metafield.',
                    'errors' => $body['errors'] ?? $body['data']['productUpdate']['userErrors']
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Metafield updated successfully.',
                'product' => $body['data']['productUpdate']['product']
            ]);

        } catch (\Exception $e) {
            Log::error("🚨 Shopify API Exception: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update metafield.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
