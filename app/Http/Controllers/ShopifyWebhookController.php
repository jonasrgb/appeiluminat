<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessShopifyWebhook;
use App\Models\ShopifyWebhookEvent;
use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use GuzzleHttp\Client;

class ShopifyWebhookController extends Controller
{
    /** @var array<string> */
    private array $allowedTopics = [
        'products/create',
        'products/update',
    ];

    public function handle(Request $request)
    {
        $topic = $request->header('X-Shopify-Topic');
        $shopDomain = $request->header('X-Shopify-Shop-Domain');
        $webhookId = $request->header('X-Shopify-Webhook-Id');
        $payload = $request->json()->all();

        Log::info('Shopify webhook received', [
            'topic'      => $topic,
            'shop'       => $shopDomain,
            'webhook_id' => $webhookId,
            'payload'    => $payload,
        ]);

        if (!in_array($topic, $this->allowedTopics, true)) {
            Log::notice('Shopify webhook topic not allowed', [
                'topic' => $topic,
                'shop'  => $shopDomain,
            ]);
            return response('', 204);
        }

        // 1) Persistă evenimentul pentru audit
        $event = ShopifyWebhookEvent::create([
            'webhook_id'  => $webhookId,
            'topic'       => $topic,
            'shop_domain' => $shopDomain,
            'payload'     => $payload,
        ]);

        Log::info('Shopify webhook stored & queued', [
            'id'    => $event->id,
            'topic' => $topic,
            'shop'  => $shopDomain,
        ]);

        // 2) Procesează în coadă (trimitem și ID-ul pentru eventuale update-uri ulterioare)
        ProcessShopifyWebhook::dispatch($topic, $shopDomain, $payload, $event->id)
        ->onQueue('webhooks');

        return response('OK', 200);
    }

    public function createWebhook()
    {
        $shopifyStore = 'eiluminat.myshopify.com'; // doar domeniul, fără https://
        $accessToken  = env('ACCESS_TOKEN_ADMIN_EILUMINAT');

        if (!$shopifyStore || !$accessToken) {
            Log::error('Missing Shopify credentials in .env file.');
            return response()->json(['error' => 'Shopify credentials are missing'], 500);
        }

        $client = new \GuzzleHttp\Client();
        $url    = "https://{$shopifyStore}/admin/api/2025-01/graphql.json";

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
            format
            includeFields
            metafieldNamespaces
            filter
            endpoint {
                __typename
                ... on WebhookHttpEndpoint { callbackUrl }
            }
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
            "callbackUrl" => "https://coolify.lustreled.ro/api/webhooks/shopify/update",
            "format" => "JSON",
            "metafieldNamespaces" => ["custom"],
            "filter" => "metafields.namespace:custom AND metafields.key:trigger AND metafields.value:true"
        
        ]
        ];

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type'            => 'application/json',
                    'X-Shopify-Access-Token'  => $accessToken,
                ],
                'json' => [
                    'query'     => $query,
                    'variables' => $variables,
                ],
                'timeout' => 15,
                'connect_timeout' => 5,
            ]);

            $body = json_decode((string) $response->getBody(), true) ?: [];

            // tratează erorile GraphQL (top-level) sau userErrors din mutație
            if (!empty($body['errors'])) {
                Log::error('Shopify Webhook GraphQL errors', $body['errors']);
                return response()->json([
                    'success' => false,
                    'message' => 'GraphQL error',
                    'errors'  => $body['errors'],
                ], 422);
            }

            $create = $body['data']['webhookSubscriptionCreate'] ?? null;
            if ($create && !empty($create['userErrors'])) {
                Log::error('Shopify Webhook userErrors', $create['userErrors']);
                return response()->json([
                    'success'    => false,
                    'message'    => 'Webhook not created',
                    'userErrors' => $create['userErrors'],
                ], 422);
            }

            Log::info('Shopify Webhook created', $body);
            return response()->json([
                'success' => true,
                'data'    => $body['data']['webhookSubscriptionCreate']['webhookSubscription'] ?? null,
            ]);

        } catch (\Throwable $e) {
            Log::error('Shopify Webhook Error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
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
                                ... on EventBridgeWebhookEndpoint { arn }
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
                    'arn' => $delivery === 'EventBridgeWebhookEndpoint' ? ($n['endpoint']['arn'] ?? null) : null,
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
}
