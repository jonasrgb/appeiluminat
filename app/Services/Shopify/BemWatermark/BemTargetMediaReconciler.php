<?php

namespace App\Services\Shopify\BemWatermark;

use App\Models\ProductMirror;
use App\Models\Shop;
use Illuminate\Support\Facades\Log;

class BemTargetMediaReconciler
{
    public function __construct(
        private readonly BemShopifyGraphqlClient $graphql,
        private readonly BemShopifyStagedUploadService $uploadService,
        private readonly BemWatermarkImageProcessor $imageProcessor,
        private readonly BemProductWatermarkMetafieldService $metafieldService,
        private readonly BemImageIdentityService $identity
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $liveImages
     * @param array<string, mixed> $manifest
     * @param array<int, array<string, mixed>> $backupImages
     * @return array{healthy: bool, reasons: array<int, string>, expected_images: int, actual_images: int, manifest_images: int}
     */
    public function assessHealth(array $liveImages, array $manifest, array $backupImages): array
    {
        $expectedCount = count($backupImages);
        $actualCount = count($liveImages);
        $manifestImages = array_values((array) ($manifest['images'] ?? []));
        $manifestCount = count($manifestImages);
        $reasons = [];

        if ($expectedCount === 0) {
            $reasons[] = 'missing_backup_images';
        }

        if ($actualCount === 0) {
            $reasons[] = 'missing_live_images';
        }

        if ($manifestCount === 0) {
            $reasons[] = 'missing_watermarked_manifest';
        }

        if ($actualCount !== $expectedCount) {
            $reasons[] = 'image_count_mismatch';
        }

        if ($manifestCount !== $expectedCount) {
            $reasons[] = 'manifest_count_mismatch';
        }

        if ($expectedCount > 0 && $actualCount === $expectedCount && $manifestCount === $expectedCount) {
            foreach (array_values($backupImages) as $index => $backupImage) {
                $liveImage = $liveImages[$index] ?? [];
                $manifestImage = $manifestImages[$index] ?? [];

                if (($manifestImage['status'] ?? null) !== 'completed') {
                    $reasons[] = 'manifest_image_incomplete';
                }

                if ($this->identity->canonicalUrl($liveImage['url'] ?? ($liveImage['src'] ?? null))
                    !== $this->identity->canonicalUrl($manifestImage['watermarked_url'] ?? null)) {
                    $reasons[] = 'watermarked_url_mismatch';
                }

                if ($this->identity->canonicalUrl($manifestImage['source_url'] ?? null)
                    !== $this->identity->canonicalUrl($backupImage['source_url'] ?? null)) {
                    $reasons[] = 'backup_source_url_mismatch';
                }
            }
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'healthy' => empty($reasons),
            'reasons' => $reasons,
            'expected_images' => $expectedCount,
            'actual_images' => $actualCount,
            'manifest_images' => $manifestCount,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $backupImages
     * @return array{status: string, repaired: bool, reasons: array<int, string>, expected_images: int, actual_images: int, manifest_images: int}
     */
    public function reconcile(
        ProductMirror $mirror,
        Shop $target,
        Shop $backupShop,
        int $backupProductId,
        string $backupProductGid,
        string $title,
        array $backupImages
    ): array {
        if (empty($backupImages)) {
            throw new \RuntimeException('BEM target reconciliation refused: clean backup has no images');
        }

        $state = $this->fetchState($target, (string) $mirror->target_product_gid);
        $health = $this->assessHealth($state['images'], $state['watermarked'], $backupImages);

        if ($health['healthy']) {
            Log::info('BEM target media reconciliation healthy; no writes needed', [
                'target_shop' => $target->domain,
                'target_product_gid' => $mirror->target_product_gid,
                'images_count' => $health['actual_images'],
            ]);

            return array_merge($health, [
                'status' => 'healthy',
                'repaired' => false,
            ]);
        }

        $tempPaths = [];

        try {
            $processedResult = $this->imageProcessor->process($target, $title, $backupImages);
            $processedImages = array_values((array) ($processedResult['processed'] ?? []));
            $tempPaths = array_values((array) ($processedResult['temp_paths'] ?? []));

            if (count($processedImages) !== count($backupImages)) {
                throw new \RuntimeException('BEM target reconciliation did not process every backup image');
            }

            foreach ($processedImages as $image) {
                if (($image['status'] ?? null) !== 'processed' || empty($image['path'])) {
                    throw new \RuntimeException(
                        'BEM target reconciliation image processing failed at position '.(int) ($image['position'] ?? 0)
                    );
                }
            }

            $uploadedImages = $this->uploadService->replaceProductImages(
                $target,
                (string) $mirror->target_product_gid,
                $processedImages
            );
            $finalImages = $this->mergeUploadedImages($processedImages, $uploadedImages);

            $payload = $this->watermarkedPayload(
                backupShop: $backupShop,
                backupProductId: $backupProductId,
                backupProductGid: $backupProductGid,
                target: $target,
                mirror: $mirror,
                images: $finalImages
            );
            $this->metafieldService->update($target, (string) $mirror->target_product_gid, $payload);
            $this->updateMirrorSnapshot($mirror, $finalImages);

            Log::notice('BEM target media reconciliation repaired target', [
                'target_shop' => $target->domain,
                'target_product_gid' => $mirror->target_product_gid,
                'reasons' => $health['reasons'],
                'images_count' => count($finalImages),
            ]);

            return array_merge($health, [
                'status' => 'repaired',
                'repaired' => true,
            ]);
        } finally {
            $this->imageProcessor->cleanup($tempPaths);
        }
    }

    /**
     * @return array{images: array<int, array<string, mixed>>, watermarked: array<string, mixed>}
     */
    private function fetchState(Shop $target, string $productGid): array
    {
        $query = <<<'GQL'
        query BemTargetMediaState($id: ID!) {
          product(id: $id) {
            id
            legacyResourceId
            images(first: 250) {
              nodes {
                id
                url
                altText
              }
            }
            metafield(namespace: "prod", key: "watermarked") {
              value
            }
          }
        }
        GQL;

        $response = $this->graphql->request($target, $query, ['id' => $productGid]);
        $product = $response['data']['product'] ?? null;
        if (!is_array($product)) {
            throw new \RuntimeException('BEM target product not found during media reconciliation');
        }

        $value = $product['metafield']['value'] ?? null;
        $watermarked = is_string($value) ? json_decode($value, true) : null;

        return [
            'images' => array_values((array) ($product['images']['nodes'] ?? [])),
            'watermarked' => is_array($watermarked) ? $watermarked : [],
        ];
    }

    private function mergeUploadedImages(array $processedImages, array $uploadedImages): array
    {
        $uploadedByPosition = [];
        foreach ($uploadedImages as $uploadedImage) {
            $uploadedByPosition[(int) ($uploadedImage['position'] ?? 0)] = $uploadedImage;
        }

        foreach ($processedImages as $index => $processedImage) {
            $position = (int) ($processedImage['position'] ?? 0);
            if (isset($uploadedByPosition[$position])) {
                $processedImages[$index] = array_merge($processedImage, $uploadedByPosition[$position]);
            }
        }

        return $processedImages;
    }

    private function watermarkedPayload(
        Shop $backupShop,
        int $backupProductId,
        string $backupProductGid,
        Shop $target,
        ProductMirror $mirror,
        array $images
    ): array {
        return [
            'status' => 'completed',
            'mode' => 'target_product_update',
            'source_shop' => $backupShop->domain,
            'source_product_id' => $backupProductId,
            'source_product_gid' => $backupProductGid,
            'target_shop' => $target->domain,
            'target_product_id' => $mirror->target_product_id,
            'target_product_gid' => $mirror->target_product_gid,
            'updated_at' => now()->toIso8601String(),
            'dry_run' => false,
            'images' => array_values(array_map(static fn (array $image) => [
                'position' => $image['position'] ?? null,
                'source_url' => $image['source_url'] ?? null,
                'watermarked_url' => $image['watermarked_url'] ?? null,
                'filename' => $image['filename'] ?? null,
                'original_extension' => $image['original_extension'] ?? null,
                'status' => $image['status'] ?? null,
            ], $images)),
        ];
    }

    private function updateMirrorSnapshot(ProductMirror $mirror, array $images): void
    {
        $snapshot = is_array($mirror->last_snapshot) ? $mirror->last_snapshot : [];
        $snapshotImages = [];

        foreach (array_values($images) as $index => $image) {
            $url = $image['watermarked_url'] ?? $image['uploaded_url'] ?? $image['source_url'] ?? null;
            $snapshotImages[] = [
                'src' => $url,
                'src_canon' => $this->identity->canonicalUrl($url),
                'alt' => $image['alt'] ?? '',
                'position' => $index + 1,
            ];
        }

        $snapshot['images'] = $snapshotImages;
        $snapshot['images_fingerprint'] = $this->fingerprintImages($snapshotImages);
        $snapshot['bem_update_media_synced_at'] = now()->toIso8601String();

        $mirror->last_snapshot = $snapshot;
        $mirror->save();
    }

    private function fingerprintImages(array $images): string
    {
        $pieces = [];
        foreach ($images as $image) {
            $pieces[] = ($image['src_canon'] ?? '').'|'.(string) ($image['alt'] ?? '');
        }

        return 'sha1:'.sha1(implode('||', $pieces));
    }
}
