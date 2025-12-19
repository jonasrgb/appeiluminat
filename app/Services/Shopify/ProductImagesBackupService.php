<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductImagesBackupService
{
    private const NAMESPACE = 'bkp';
    private const KEY = 'old_images';

    /**
     * Persists a simplified snapshot of product images (position + url) inside
     * the bkp.old_images metafield for the given shop/product.
     */
    public static function syncFromImages(Shop $shop, string $productGid, array $images): void
    {
        if (empty($productGid) || empty($shop->domain) || empty($shop->access_token)) {
            Log::warning('ProductImagesBackup skipped: missing shop credentials or product id', [
                'shop_id' => $shop->id ?? null,
                'product_gid' => $productGid,
            ]);
            return;
        }

        $payload = self::normalizeImages($images);
        if (empty($payload)) {
            Log::info('ProductImagesBackup skipped: no images to persist', [
                'shop' => $shop->domain,
                'product_gid' => $productGid,
            ]);
            return;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            Log::warning('ProductImagesBackup failed to encode JSON', [
                'shop' => $shop->domain,
                'product_gid' => $productGid,
            ]);
            return;
        }

        $mutation = <<<'GQL'
        mutation SetOldImagesMetafield($metafields: [MetafieldsSetInput!]!) {
          metafieldsSet(metafields: $metafields) {
            metafields { id }
            userErrors { field message }
          }
        }
        GQL;

        $variables = [
            'metafields' => [[
                'ownerId'   => $productGid,
                'namespace' => self::NAMESPACE,
                'key'       => self::KEY,
                'type'      => 'json',
                'value'     => $json,
            ]],
        ];

        $version  = $shop->api_version ?: '2025-01';
        $endpoint = "https://{$shop->domain}/admin/api/{$version}/graphql.json";

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $shop->access_token,
                'Content-Type'           => 'application/json',
            ])->post($endpoint, [
                'query'     => $mutation,
                'variables' => $variables,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ProductImagesBackup request failed', [
                'shop' => $shop->domain,
                'product_gid' => $productGid,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $body = $response->json();
        if ($response->failed() || !empty($body['errors'])) {
            Log::warning('ProductImagesBackup GraphQL errors', [
                'shop' => $shop->domain,
                'product_gid' => $productGid,
                'status' => $response->status(),
                'errors' => $body['errors'] ?? null,
            ]);
            return;
        }

        $userErrors = $body['data']['metafieldsSet']['userErrors'] ?? [];
        if (!empty($userErrors)) {
            Log::warning('ProductImagesBackup userErrors', [
                'shop' => $shop->domain,
                'product_gid' => $productGid,
                'userErrors' => $userErrors,
            ]);
        }
    }

    /**
     * Reduces raw image array to position/url pairs expected by the metafield.
     *
     * @return array<int, array{position:int,url:string}>
     */
    private static function normalizeImages(array $images): array
    {
        $normalized = [];
        foreach ($images as $img) {
            $url = $img['src'] ?? $img['src_canon'] ?? null;
            if (!$url) {
                continue;
            }

            $normalized[] = [
                'position' => (int)($img['position'] ?? 0),
                'url'      => $url,
            ];
        }

        usort($normalized, static fn ($a, $b) => $a['position'] <=> $b['position']);

        return $normalized;
    }
}
