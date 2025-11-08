<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use GuzzleHttp\Client;

class ShopifyCustomerController extends Controller
{
    public function index()
    {
        //
    }

    public function eiluminat(Request $request)
    {
        $payload = $request->all();
        $tagsCollected = [];

        $tagSources = [
            $payload['tags'] ?? null,
            $payload['customer']['tags'] ?? null,
        ];

        foreach ($tagSources as $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $pieces = array_map('trim', explode(',', $value));
                $tagsCollected = array_merge($tagsCollected, $pieces);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if (is_string($item)) {
                        $tagsCollected[] = trim($item);
                    }
                }
            }
        }

        $normalizedTags = array_unique(array_filter(array_map('strtolower', $tagsCollected)));

        if (in_array('bf-customer', $normalizedTags, true)) {
            $customerGid = $payload['id']
                ?? $payload['customer']['id']
                ?? $payload['customerId']
                ?? null;

            if (!$customerGid) {
                Log::warning('BF customer webhook without identifiable customer ID (eiluminat)', [
                    'payload' => $payload,
                ]);
            } else {
                $shopifyStore = env('SHOPIFY_SHOP_EILUMINAT_URL');
                $accessToken  = env('ACCESS_TOKEN_ADMIN_EILUMINAT');

                $response = $this->createDiscountCodeForCustomer($shopifyStore, $accessToken, $customerGid);

                if ($response instanceof \Illuminate\Http\JsonResponse) {
                    $data = $response->getData(true);
                    if (!empty($data['success'])) {
                        Log::info('Discount code generated for BF customer (eiluminat)', [
                            'customer_gid' => $customerGid,
                            'code'         => $data['data']['code'] ?? null,
                        ]);
                    } else {
                        Log::error('Discount code generation failed for BF customer (eiluminat)', [
                            'customer_gid' => $customerGid,
                            'response'     => $data,
                        ]);
                    }
                } else {
                    Log::error('Discount code generation returned unexpected response (eiluminat)', [
                        'customer_gid' => $customerGid,
                        'response'     => $response,
                    ]);
                }
            }
        }

        return $this->handleIncomingWebhook('eiluminat', $request);
    }

    public function power(Request $request)
    {
        $payload = $request->all();
        $tagsCollected = [];

        $tagSources = [
            $payload['tags'] ?? null,
            $payload['customer']['tags'] ?? null,
        ];

        foreach ($tagSources as $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $pieces = array_map('trim', explode(',', $value));
                $tagsCollected = array_merge($tagsCollected, $pieces);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if (is_string($item)) {
                        $tagsCollected[] = trim($item);
                    }
                }
            }
        }

        $normalizedTags = array_unique(array_filter(array_map('strtolower', $tagsCollected)));

        if (in_array('bf-customer', $normalizedTags, true)) {
            $customerGid = $payload['id']
                ?? $payload['customer']['id']
                ?? $payload['customerId']
                ?? null;

            if (!$customerGid) {
                Log::warning('BF customer webhook without identifiable customer ID (power)', [
                    'payload' => $payload,
                ]);
            } else {
                $shopifyStore = env('STORE_URL_POWERLED');
                $accessToken  = env('POWER_BF_TOKEN');

                $response = $this->createDiscountCodeForCustomer($shopifyStore, $accessToken, $customerGid);

                if ($response instanceof \Illuminate\Http\JsonResponse) {
                    $data = $response->getData(true);
                    if (!empty($data['success'])) {
                        Log::info('Discount code generated for BF customer (power)', [
                            'customer_gid' => $customerGid,
                            'code'         => $data['data']['code'] ?? null,
                        ]);
                    } else {
                        Log::error('Discount code generation failed for BF customer (power)', [
                            'customer_gid' => $customerGid,
                            'response'     => $data,
                        ]);
                    }
                } else {
                    Log::error('Discount code generation returned unexpected response (power)', [
                        'customer_gid' => $customerGid,
                        'response'     => $response,
                    ]);
                }
            }
        }

        return $this->handleIncomingWebhook('power', $request);
    }

    public function lustre(Request $request)
    {
        $payload = $request->all();
        $tagsCollected = [];

        $tagSources = [
            $payload['tags'] ?? null,
            $payload['customer']['tags'] ?? null,
        ];

        foreach ($tagSources as $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $pieces = array_map('trim', explode(',', $value));
                $tagsCollected = array_merge($tagsCollected, $pieces);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if (is_string($item)) {
                        $tagsCollected[] = trim($item);
                    }
                }
            }
        }

        $normalizedTags = array_unique(array_filter(array_map('strtolower', $tagsCollected)));

        if (in_array('bf-customer', $normalizedTags, true)) {
            $customerGid = $payload['id']
                ?? $payload['customer']['id']
                ?? $payload['customerId']
                ?? null;

            if (!$customerGid) {
                Log::warning('BF customer webhook without identifiable customer ID (lustre)', [
                    'payload' => $payload,
                ]);
            } else {
                $shopifyStore = env('STORE_URL_LUSTRELED_WEB');
                $accessToken  = env('ACCESS_TOKEN_ADMIN_LUSTRELED_WEB');

                $response = $this->createDiscountCodeForCustomer($shopifyStore, $accessToken, $customerGid);

                if ($response instanceof \Illuminate\Http\JsonResponse) {
                    $data = $response->getData(true);
                    if (!empty($data['success'])) {
                        Log::info('Discount code generated for BF customer (lustre)', [
                            'customer_gid' => $customerGid,
                            'code'         => $data['data']['code'] ?? null,
                        ]);
                    } else {
                        Log::error('Discount code generation failed for BF customer (lustre)', [
                            'customer_gid' => $customerGid,
                            'response'     => $data,
                        ]);
                    }
                } else {
                    Log::error('Discount code generation returned unexpected response (lustre)', [
                        'customer_gid' => $customerGid,
                        'response'     => $response,
                    ]);
                }
            }
        }

        return $this->handleIncomingWebhook('lustre', $request);
    }

    /**
     * EILUMINAT: creează webhook pentru CUSTOMERS_UPDATE cu filtru pe tag-ul bf-customer
     */
    public function generateWebhookEiluminat()
    {
        $shopifyStore = env('SHOPIFY_SHOP_EILUMINAT_URL');               // ex: eiluminat.myshopify.com
        $accessToken  = env('ACCESS_TOKEN_ADMIN_EILUMINAT');         // token admin app
        $callbackUrl  = "https://coolify.lustreled.ro/api/customer/eiluminat" ;     // ex: https://coolify.lustreled.ro/api/customer/eiluminat

        return $this->createCustomerTagWebhook($shopifyStore, $accessToken, $callbackUrl);
    }

    /**
     * EILUMINAT: listează webhook-urile existente (util pentru tinker/debug)
     */
    public function listWebhooksEiluminat()
    {
        $shopifyStore = env('SHOPIFY_SHOP_EILUMINAT_URL');
        $accessToken  = env('ACCESS_TOKEN_ADMIN_EILUMINAT');

        return $this->fetchWebhookSubscriptions($shopifyStore, $accessToken);
    }

    /**
     * EILUMINAT: șterge un webhook specific după ID (gid://...).
     */
    public function deleteWebhookEiluminat(string $webhookGid)
    {
        $shopifyStore = env('SHOPIFY_SHOP_EILUMINAT_URL');
        $accessToken  = env('ACCESS_TOKEN_ADMIN_EILUMINAT');

        return $this->deleteWebhookSubscription($shopifyStore, $accessToken, $webhookGid);
    }

    /**
     * POWER: listează webhook-urile existente (util pentru tinker/debug)
     */
    public function listWebhooksPower()
    {
        $shopifyStore = env('STORE_URL_POWERLED');
        $accessToken  = env('POWER_BF_TOKEN');

        return $this->fetchWebhookSubscriptions($shopifyStore, $accessToken);
    }

    /**
     * POWER: șterge un webhook specific după ID (gid://...).
     */
    public function deleteWebhookPower(string $webhookGid)
    {
        $shopifyStore = env('STORE_URL_POWERLED');
        $accessToken  = env('POWER_BF_TOKEN');

        return $this->deleteWebhookSubscription($shopifyStore, $accessToken, $webhookGid);
    }

    /**
     * LUSTRE: listează webhook-urile existente (util pentru tinker/debug)
     */
    public function listWebhooksLustre()
    {
        $shopifyStore = env('STORE_URL_LUSTRELED_WEB');
        $accessToken  = env('LUSTRE_BF_TOKEN');

        return $this->fetchWebhookSubscriptions($shopifyStore, $accessToken);
    }

    /**
     * LUSTRE: șterge un webhook specific după ID (gid://...).
     */
    public function deleteWebhookLustre(string $webhookGid)
    {
        $shopifyStore = env('STORE_URL_LUSTRELED_WEB');
        $accessToken  = env('LUSTRE_BF_TOKEN');

        return $this->deleteWebhookSubscription($shopifyStore, $accessToken, $webhookGid);
    }

    /**
     * Creează un discount code personalizat (BF10) pentru un client anume.
     */
    public function createDiscountCodeForCustomer(string $shopifyStore, string $accessToken, string $customerGid)
    {
        if (!$shopifyStore || !$accessToken || !$customerGid) {
            Log::error('Missing parameters for discount code creation.', [
                'shop'       => $shopifyStore,
                'has_token'  => (bool) $accessToken,
                'customerId' => $customerGid,
            ]);
            return response()->json(['error' => 'Missing shop, token or customer id'], 422);
        }

        $client = new Client();
        $url    = "https://{$shopifyStore}/admin/api/2025-01/graphql.json";

        $code = sprintf('BF10-%s', strtoupper(Str::random(6)));

        $mutation = <<<'GRAPHQL'
        mutation discountCodeBasicCreate($discount: DiscountCodeBasicInput!) {
          discountCodeBasicCreate(basicCodeDiscount: $discount) {
            codeDiscountNode {
              id
              codeDiscount {
                ... on DiscountCodeBasic {
                  title
                  codes(first: 1) {
                    edges {
                      node {
                        code
                      }
                    }
                  }
                  combinesWith {
                    orderDiscounts
                    productDiscounts
                    shippingDiscounts
                  }
                }
              }
            }
            userErrors {
              field
              message
            }
          }
        }
        GRAPHQL;

        $timezone = 'Europe/Bucharest';

        // 07/11/2025 00:00 → începutul zilei (local)
        $startLocal = Carbon::create(2025, 11, 7, 0, 0, 0, $timezone);

        // 07/11/2025 23:59:59 → sfârșitul zilei (local)
        // (alternativ poți seta 08/11/2025 00:00 dacă 'endsAt' e tratat ca exclusiv)
        $endLocal   = Carbon::create(2025, 11, 7, 23, 59, 59, $timezone);

        $startsAt = $startLocal->copy()->utc()->toIso8601String(); // 2025-11-06T22:00:00Z
        $endsAt   = $endLocal->copy()->utc()->toIso8601String();   // 2025-11-07T21:59:59Z

        $startsAt = $startLocal->clone()->setTimezone('UTC')->toIso8601String();
        $endsAt   = $endLocal->clone()->setTimezone('UTC')->toIso8601String();

        $variables = [
            'discount' => [
                'title'                 => 'BF10 Personalized Discount',
                'code'                  => $code,
                'startsAt'              => $startsAt,
                'endsAt'                => $endsAt,
                'minimumRequirement'    => [
                    'subtotal' => [
                        'greaterThanOrEqualToSubtotal' => '500.0',
                    ],
                ],
                'combinesWith'          => [
                    'orderDiscounts'   => false,
                    'productDiscounts' => false,
                    'shippingDiscounts'=> false,
                ],
                'usageLimit'            => 1,
                'appliesOncePerCustomer'=> true,
                'customerSelection'     => [
                    'customers' => [
                        'add' => [$customerGid],
                    ],
                ],
                'customerGets'          => [
                    'value' => [
                        'percentage' => 0.10,
                    ],
                    'items' => [
                        'all' => true,
                    ],
                ],
            ],
        ];

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type'           => 'application/json',
                    'X-Shopify-Access-Token' => $accessToken,
                ],
                'json' => [
                    'query'     => $mutation,
                    'variables' => $variables,
                ],
                'timeout'         => 15,
                'connect_timeout' => 5,
            ]);

            $body = json_decode((string) $response->getBody(), true) ?: [];

            if (!empty($body['errors'])) {
                Log::error('Discount code GraphQL errors', $body['errors']);
                return response()->json([
                    'success' => false,
                    'message' => 'GraphQL error',
                    'errors'  => $body['errors'],
                ], 422);
            }

            $create = $body['data']['discountCodeBasicCreate'] ?? null;
            if ($create && !empty($create['userErrors'])) {
                Log::error('Discount code userErrors', $create['userErrors']);
                return response()->json([
                    'success'    => false,
                    'message'    => 'Discount not created',
                    'userErrors' => $create['userErrors'],
                ], 422);
            }

            Log::info('Discount code created', [
                'shop'       => $shopifyStore,
                'customerId' => $customerGid,
                'code'       => $code,
            ]);

            return response()->json([
                'success' => true,
                'data'    => [
                    'code' => $code,
                    'node' => $create['codeDiscountNode'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Discount code creation failed', [
                'shop'       => $shopifyStore,
                'customerId' => $customerGid,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POWER: creează webhook pentru CUSTOMERS_UPDATE cu filtru pe tag-ul bf-customer
     */
    public function generateWebhookPower()
    {
        $shopifyStore = env('STORE_URL_POWERLED');                   // ex: power.myshopify.com
        $accessToken  = env('POWER_BF_TOKEN');
        $callbackUrl  = "https://coolify.lustreled.ro/api/customer/power" ;          // ex: https://coolify.lustreled.ro/api/customer/power

        return $this->createCustomerTagWebhook($shopifyStore, $accessToken, $callbackUrl);
    }

    /**
     * LUSTRE: creează webhook pentru CUSTOMERS_UPDATE cu filtru pe tag-ul bf-customer
     */
    public function generateWebhookLustre()
    {
        $shopifyStore = env('STORE_URL_LUSTRELED_WEB');                  // ex: lustreled.myshopify.com
        $accessToken  = env('LUSTRE_BF_TOKEN');
        $callbackUrl  = "https://coolify.lustreled.ro/api/customer/lustre";          // ex: https://coolify.lustreled.ro/api/webhooks/customers-update

        return $this->createCustomerTagWebhook($shopifyStore, $accessToken, $callbackUrl);
    }

    /**
     * Helper comun pentru a crea webhook-ul CUSTOMERS_UPDATE cu filtru "tags:bf-customer"
     */
    private function createCustomerTagWebhook(?string $shopifyStore, ?string $accessToken, ?string $callbackUrl)
    {
        if (!$shopifyStore || !$accessToken || !$callbackUrl) {
            Log::error('Missing Shopify credentials or callback URL for webhook creation.', [
                'shop'      => $shopifyStore,
                'has_token' => (bool) $accessToken,
                'callback'  => $callbackUrl,
            ]);
            return response()->json(['error' => 'Missing shop, token or callback URL in .env'], 422);
        }

        $client = new \GuzzleHttp\Client();
        // Versiune recentă care acceptă `uri` în WebhookSubscriptionInput
        $url = "https://{$shopifyStore}/admin/api/2025-10/graphql.json";

        $query = <<<'GRAPHQL'
            mutation webhookSubscriptionCreate(
            $topic: WebhookSubscriptionTopic!,
            $webhookSubscription: WebhookSubscriptionInput!
            ) {
            webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
                webhookSubscription {
                id
                topic
                uri
                filter
                }
                userErrors {
                field
                message
                }
            }
            }
        GRAPHQL;

        $variables = [
            'topic' => 'CUSTOMER_TAGS_ADDED',
            'webhookSubscription' => [
                'uri'    => $callbackUrl,
                'format' => 'JSON',
                // Dacă vrei să livreze doar când se adaugă exact bf-customer, decomentează linia de mai jos:
                // 'filter' => 'tags_added:"bf-customer"',
            ],
        ];

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type'           => 'application/json',
                    'X-Shopify-Access-Token' => $accessToken,
                ],
                'json' => [
                    'query'     => $query,
                    'variables' => $variables,
                ],
                'timeout'         => 15,
                'connect_timeout' => 5,
            ]);

            $body = json_decode((string) $response->getBody(), true) ?: [];

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
                'data'    => $create['webhookSubscription'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Shopify Webhook Error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }






    private function handleIncomingWebhook(string $store, Request $request)
    {
        $jsonPayload = $request->json()->all();
        $payload = !empty($jsonPayload) ? $jsonPayload : $request->all();

        Log::info('Shopify customer webhook received', [
            'store'   => $store,
            'headers' => [
                'shop_domain' => $request->header('x-shopify-shop-domain'),
                'topic'       => $request->header('x-shopify-topic'),
                'hmac'        => $request->header('x-shopify-hmac-sha256'),
            ],
            'payload' => $payload,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Obține lista de webhook-uri existente pentru shop-ul dat.
     */
    private function fetchWebhookSubscriptions(?string $shopifyStore, ?string $accessToken)
    {
        if (!$shopifyStore || !$accessToken) {
            Log::error('Missing Shopify credentials for webhook listing.', [
                'shop' => $shopifyStore,
                'has_token' => (bool) $accessToken,
            ]);
            return response()->json(['error' => 'Missing shop or token in .env'], 422);
        }

        $client = new Client();
        $url    = "https://{$shopifyStore}/admin/api/2025-01/graphql.json";

        $query = <<<'GRAPHQL'
        query webhookSubscriptionsList($first: Int!) {
          webhookSubscriptions(first: $first) {
            edges {
              node {
                id
                topic
                format
                callbackUrl
                filter
                createdAt
                updatedAt
              }
            }
          }
        }
        GRAPHQL;

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type'           => 'application/json',
                    'X-Shopify-Access-Token' => $accessToken,
                ],
                'json' => [
                    'query'     => $query,
                    'variables' => [
                        'first' => 100,
                    ],
                ],
                'timeout'         => 15,
                'connect_timeout' => 5,
            ]);

            $body = json_decode((string) $response->getBody(), true) ?: [];

            if (!empty($body['errors'])) {
                Log::error('Shopify Webhook list GraphQL errors', $body['errors']);
                return response()->json([
                    'success' => false,
                    'message' => 'GraphQL error',
                    'errors'  => $body['errors'],
                ], 422);
            }

            Log::info('Shopify webhook list fetched', [
                'shop'  => $shopifyStore,
                'count' => count($body['data']['webhookSubscriptions']['edges'] ?? []),
            ]);

            return response()->json([
                'success' => true,
                'data'    => $body['data']['webhookSubscriptions']['edges'] ?? [],
            ]);
        } catch (\Throwable $e) {
            Log::error('Shopify webhook list error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Șterge un webhook după ID.
     */
    private function deleteWebhookSubscription(?string $shopifyStore, ?string $accessToken, string $webhookGid)
    {
        if (!$shopifyStore || !$accessToken || !$webhookGid) {
            Log::error('Missing Shopify credentials or webhook ID for deletion.', [
                'shop' => $shopifyStore,
                'has_token' => (bool) $accessToken,
                'webhook_id' => $webhookGid,
            ]);
            return response()->json(['error' => 'Missing shop, token or webhook ID'], 422);
        }

        $client = new Client();
        $url    = "https://{$shopifyStore}/admin/api/2025-01/graphql.json";

        $mutation = <<<'GRAPHQL'
        mutation webhookSubscriptionDelete($id: ID!) {
          webhookSubscriptionDelete(id: $id) {
            deletedWebhookSubscriptionId
            userErrors {
              field
              message
            }
          }
        }
        GRAPHQL;

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type'           => 'application/json',
                    'X-Shopify-Access-Token' => $accessToken,
                ],
                'json' => [
                    'query'     => $mutation,
                    'variables' => [
                        'id' => $webhookGid,
                    ],
                ],
                'timeout'         => 15,
                'connect_timeout' => 5,
            ]);

            $body = json_decode((string) $response->getBody(), true) ?: [];

            if (!empty($body['errors'])) {
                Log::error('Shopify Webhook delete GraphQL errors', $body['errors']);
                return response()->json([
                    'success' => false,
                    'message' => 'GraphQL error',
                    'errors'  => $body['errors'],
                ], 422);
            }

            $deleteResult = $body['data']['webhookSubscriptionDelete'] ?? null;
            if ($deleteResult && !empty($deleteResult['userErrors'])) {
                Log::error('Shopify Webhook delete userErrors', $deleteResult['userErrors']);
                return response()->json([
                    'success'    => false,
                    'message'    => 'Webhook not deleted',
                    'userErrors' => $deleteResult['userErrors'],
                ], 422);
            }

            Log::info('Shopify webhook deleted', [
                'shop' => $shopifyStore,
                'id'   => $webhookGid,
            ]);

            return response()->json([
                'success' => true,
                'data'    => $deleteResult['deletedWebhookSubscriptionId'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Shopify webhook delete error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
