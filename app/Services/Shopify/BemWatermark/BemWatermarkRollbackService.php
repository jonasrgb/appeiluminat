<?php

namespace App\Services\Shopify\BemWatermark;

use App\Models\Shop;
use Illuminate\Support\Facades\Log;

class BemWatermarkRollbackService
{
    public function __construct(private readonly BemShopifyGraphqlClient $graphql)
    {
    }

    public function rollbackProduct(Shop $target, string $productGid, bool $dryRun = false): array
    {
        $watermarked = $this->fetchWatermarkedMetafield($target, $productGid);
        if (!$watermarked) {
            throw new \RuntimeException('prod.watermarked metafield not found for '.$productGid);
        }

        $images = array_values(array_filter(
            (array) ($watermarked['images'] ?? []),
            static fn ($image) => !empty($image['source_url'])
        ));

        if (empty($images)) {
            throw new \RuntimeException('prod.watermarked has no source images for '.$productGid);
        }

        $mediaInputs = array_map(static fn ($image) => [
            'mediaContentType' => 'IMAGE',
            'originalSource' => $image['source_url'],
            'alt' => $image['filename'] ?? null,
        ], $images);

        if ($dryRun) {
            Log::info('BEM watermark rollback dry-run', [
                'target_shop' => $target->domain,
                'product_gid' => $productGid,
                'images_count' => count($mediaInputs),
            ]);

            return [
                'target_shop' => $target->domain,
                'product_gid' => $productGid,
                'images_count' => count($mediaInputs),
                'dry_run' => true,
            ];
        }

        $mediaIds = $this->fetchProductMediaIds($target, $productGid);
        $this->replaceMedia($target, $productGid, $mediaIds, $mediaInputs);
        $this->markRollbackInMetafield($target, $productGid, $watermarked);

        Log::info('BEM watermark rollback completed', [
            'target_shop' => $target->domain,
            'product_gid' => $productGid,
            'images_count' => count($mediaInputs),
        ]);

        return [
            'target_shop' => $target->domain,
            'product_gid' => $productGid,
            'images_count' => count($mediaInputs),
            'dry_run' => false,
        ];
    }

    private function fetchWatermarkedMetafield(Shop $target, string $productGid): ?array
    {
        $query = <<<'GQL'
        query BemWatermarkedMetafield($id: ID!) {
          product(id: $id) {
            metafield(namespace: "prod", key: "watermarked") {
              value
            }
          }
        }
        GQL;

        $response = $this->graphql->request($target, $query, ['id' => $productGid]);
        $value = $response['data']['product']['metafield']['value'] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<int, string>
     */
    private function fetchProductMediaIds(Shop $target, string $productGid): array
    {
        $query = <<<'GQL'
        query BemRollbackProductMediaIds($id: ID!) {
          product(id: $id) {
            media(first: 250) {
              nodes { id }
            }
          }
        }
        GQL;

        $response = $this->graphql->request($target, $query, ['id' => $productGid]);
        $nodes = $response['data']['product']['media']['nodes'] ?? [];

        return array_values(array_filter(array_map(static fn ($node) => $node['id'] ?? null, $nodes)));
    }

    private function replaceMedia(Shop $target, string $productGid, array $mediaIds, array $mediaInputs): void
    {
        $mutation = <<<'GQL'
        mutation BemRollbackProductImages($productId: ID!, $mediaIds: [ID!]!, $media: [CreateMediaInput!]!) {
          deleteResult: productDeleteMedia(productId: $productId, mediaIds: $mediaIds) {
            deletedMediaIds
            mediaUserErrors { field message }
          }
          createResult: productCreateMedia(productId: $productId, media: $media) {
            media { id status }
            mediaUserErrors { field message }
          }
        }
        GQL;

        $response = $this->graphql->request($target, $mutation, [
            'productId' => $productGid,
            'mediaIds' => $mediaIds,
            'media' => $mediaInputs,
        ]);

        $deleteErrors = $response['data']['deleteResult']['mediaUserErrors'] ?? [];
        $createErrors = $response['data']['createResult']['mediaUserErrors'] ?? [];
        if (!empty($deleteErrors) || !empty($createErrors)) {
            throw new \RuntimeException('BEM rollback product images userErrors: '.json_encode([
                'delete' => $deleteErrors,
                'create' => $createErrors,
            ]));
        }
    }

    private function markRollbackInMetafield(Shop $target, string $productGid, array $watermarked): void
    {
        $watermarked['rolled_back_at'] = now()->toIso8601String();
        $watermarked['rollback_status'] = 'original_images_restored';

        $mutation = <<<'GQL'
        mutation BemRollbackMarkMetafield($metafields: [MetafieldsSetInput!]!) {
          metafieldsSet(metafields: $metafields) {
            metafields { id namespace key type }
            userErrors { field message }
          }
        }
        GQL;

        $response = $this->graphql->request($target, $mutation, [
            'metafields' => [[
                'ownerId' => $productGid,
                'namespace' => 'prod',
                'key' => 'watermarked',
                'type' => 'json',
                'value' => json_encode($watermarked, JSON_UNESCAPED_SLASHES),
            ]],
        ]);

        $errors = $response['data']['metafieldsSet']['userErrors'] ?? [];
        if (!empty($errors)) {
            throw new \RuntimeException('BEM rollback metafield userErrors: '.json_encode($errors));
        }
    }
}
