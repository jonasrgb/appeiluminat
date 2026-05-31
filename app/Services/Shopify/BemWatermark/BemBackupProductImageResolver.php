<?php

namespace App\Services\Shopify\BemWatermark;

use App\Models\ProductMirror;
use App\Models\Shop;
use Illuminate\Support\Facades\Log;

class BemBackupProductImageResolver
{
    public function __construct(private readonly BemShopifyGraphqlClient $graphql)
    {
    }

    public function resolve(int $sourceShopId, int $sourceProductId): BemBackupProductImageResult
    {
        $backupDomain = strtolower((string) config('features.bem_watermark_sync.backup_shop_domain'));
        $backupShop = Shop::whereRaw('LOWER(domain) = ?', [$backupDomain])->first();

        if (!$backupShop) {
            return BemBackupProductImageResult::notReady('Backup shop not found');
        }

        $mirror = ProductMirror::where([
            'source_shop_id' => $sourceShopId,
            'source_product_id' => $sourceProductId,
            'target_shop_id' => $backupShop->id,
        ])->first();

        if (!$mirror || !$mirror->target_product_gid) {
            return BemBackupProductImageResult::notReady('Backup product mirror not ready');
        }

        $query = <<<'GQL'
        query BemBackupProductImages($id: ID!) {
          product(id: $id) {
            id
            legacyResourceId
            title
            images(first: 250) {
              nodes {
                id
                url
                altText
              }
            }
          }
        }
        GQL;

        $response = $this->graphql->request($backupShop, $query, [
            'id' => $mirror->target_product_gid,
        ]);

        $product = $response['data']['product'] ?? null;
        if (!$product) {
            return BemBackupProductImageResult::notReady('Backup product not found in Shopify');
        }

        $images = [];
        foreach (($product['images']['nodes'] ?? []) as $index => $node) {
            $url = $node['url'] ?? null;
            if (!$url) {
                continue;
            }

            $images[] = [
                'position' => $index + 1,
                'source_url' => $url,
                'image_id' => $node['id'] ?? null,
                'alt' => $node['altText'] ?? null,
                'original_extension' => $this->extensionFromUrl($url),
            ];
        }

        if (empty($images)) {
            Log::warning('BEM watermark backup product has no images', [
                'backup_shop' => $backupShop->domain,
                'backup_product_gid' => $mirror->target_product_gid,
                'source_product_id' => $sourceProductId,
            ]);

            return BemBackupProductImageResult::notReady('Backup product has no images');
        }

        return BemBackupProductImageResult::ready(
            backupShop: $backupShop,
            sourceProductId: (int) ($mirror->target_product_id ?: ($product['legacyResourceId'] ?? 0)),
            sourceProductGid: $mirror->target_product_gid,
            images: $images
        );
    }

    private function extensionFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension !== '' ? $extension : 'jpg';
    }
}
