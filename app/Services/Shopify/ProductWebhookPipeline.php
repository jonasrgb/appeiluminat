<?php

namespace App\Services\Shopify;

use App\Jobs\LogShopifyProductImages;
use Illuminate\Support\Facades\Log;

class ProductWebhookPipeline
{
    /**
     * Dispatch the product images logging + watermark job sequence.
     */
    public static function dispatchImages(
        string $shopDomain,
        array $payload,
        int $delaySeconds = 30,
        string $queue = 'webhooks'
    ): void {
        $productGid = $payload['admin_graphql_api_id'] ?? null;
        if (!$productGid) {
            Log::warning('Product webhook missing product GID for image pipeline', [
                'shop' => $shopDomain,
                'payload_keys' => array_keys($payload),
            ]);
            return;
        }

        $productId = (int)($payload['id'] ?? 0);
        $handle    = (string)($payload['handle'] ?? 'product');
        $title     = (string)($payload['title'] ?? '');
        $needsWatermark = true;

        Log::info('Watermark tag debug', [
            'shop'   => $shopDomain,
            'product_id' => $productId ?: null,
            'tags_raw'   => $payload['tags'] ?? null,
            'needs_watermark' => $needsWatermark,
        ]);

        Log::info('Product webhook pipeline dispatch', [
            'shop' => $shopDomain,
            'product_id' => $productId,
            'product_gid' => $productGid,
            'delay_seconds' => $delaySeconds,
            'queue' => $queue,
        ]);

        LogShopifyProductImages::dispatch(
            shopDomain: $shopDomain,
            productGid: $productGid,
            attempt: 1,
            productId: $productId,
            handle: $handle,
            title: $title,
            shouldWatermark: $needsWatermark
        )->delay(now()->addSeconds($delaySeconds))
         ->onQueue($queue);
    }

    public static function shouldApplyWatermark(array $payload): bool
    {
        $rawTags = $payload['tags'] ?? '';

        if (is_string($rawTags)) {
            $list = array_filter(array_map(
                fn ($tag) => strtolower(trim($tag)),
                explode(',', $rawTags)
            ));
        } elseif (is_array($rawTags)) {
            $list = array_map(
                fn ($tag) => strtolower(trim((string) $tag)),
                $rawTags
            );
        } else {
            $list = [];
        }

        return true;
    }
}
