<?php

namespace App\Services\Shopify\BemWatermark;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BemShopifyGraphqlClient
{
    public function request(Shop $shop, string $query, array $variables = []): array
    {
        $version = $shop->api_version ?: '2025-01';
        $endpoint = "https://{$shop->domain}/admin/api/{$version}/graphql.json";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $shop->access_token,
            'Content-Type' => 'application/json',
        ])->post($endpoint, [
            'query' => $query,
            'variables' => $variables,
        ]);

        $body = $response->json() ?: [];
        if ($response->failed() || !empty($body['errors'])) {
            Log::error('BEM watermark Shopify GraphQL failed', [
                'shop' => $shop->domain,
                'status' => $response->status(),
                'errors' => $body['errors'] ?? null,
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('BEM watermark Shopify GraphQL failed for '.$shop->domain);
        }

        return $body;
    }
}
