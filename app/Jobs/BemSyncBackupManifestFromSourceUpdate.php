<?php

namespace App\Jobs;

use App\Mail\BemWatermarkFailedMail;
use App\Models\ProductMirror;
use App\Models\Shop;
use App\Services\Shopify\BemWatermark\BemBackupManifestService;
use App\Services\Shopify\BemWatermark\BemBackupProductImageResolver;
use App\Services\Shopify\BemWatermark\BemImageIdentityService;
use App\Services\Shopify\BemWatermark\BemProductWatermarkMetafieldService;
use App\Services\Shopify\BemWatermark\BemShopifyGraphqlClient;
use App\Services\Shopify\BemWatermark\BemShopifyStagedUploadService;
use App\Services\Shopify\BemWatermark\BemWatermarkEligibilityService;
use App\Services\Shopify\BemWatermark\BemWatermarkImageProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class BemSyncBackupManifestFromSourceUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 600;
    public array $backoff = [60, 120, 180, 300];

    public function __construct(
        public int $sourceShopId,
        public int $sourceProductId,
        public string $sourceProductGid,
        public string $title,
        public array $sourcePayload
    ) {
    }

    public function handle(
        BemWatermarkEligibilityService $eligibility,
        BemBackupProductImageResolver $backupResolver,
        BemBackupManifestService $manifestService,
        BemImageIdentityService $identity,
        BemShopifyGraphqlClient $graphql,
        BemShopifyStagedUploadService $uploadService,
        BemWatermarkImageProcessor $imageProcessor,
        BemProductWatermarkMetafieldService $metafieldService
    ): void {
        $source = Shop::findOrFail($this->sourceShopId);

        if (!$eligibility->isUpdateManifestEnabled()) {
            Log::info('BEM update manifest sync skipped: feature disabled', [
                'source_shop' => $source->domain,
                'source_product_id' => $this->sourceProductId,
            ]);
            return;
        }

        if (!$eligibility->isEligiblePayloadForSource($this->sourcePayload, $source)) {
            Log::info('BEM update manifest sync skipped: source payload not eligible', [
                'source_shop' => $source->domain,
                'source_product_id' => $this->sourceProductId,
            ]);
            return;
        }

        $lock = Cache::lock($this->lockKey($source), 900);
        if (!$lock->get()) {
            Log::info('BEM update manifest sync waiting for lock', [
                'source_shop' => $source->domain,
                'source_product_gid' => $this->sourceProductGid,
            ]);
            $this->release(30);
            return;
        }

        $tempPaths = [];

        try {
            $backup = $backupResolver->resolve($this->sourceShopId, $this->sourceProductId);
            if (!$backup->ready || !$backup->backupShop || !$backup->sourceProductGid) {
                if ($this->attempts() >= $this->tries) {
                    throw new \RuntimeException('BEM update manifest backup not ready: '.($backup->reason ?: 'unknown'));
                }

                Log::warning('BEM update manifest waiting for backup product', [
                    'source_shop' => $source->domain,
                    'source_product_id' => $this->sourceProductId,
                    'attempt' => $this->attempts(),
                    'reason' => $backup->reason,
                ]);
                $this->release(60);
                return;
            }

            $sourceWatermarked = $this->fetchSourceWatermarked($graphql, $source);
            $sourceImages = $this->sourceImages($identity);
            $desiredOriginalImages = $this->desiredOriginalImages($sourceImages, $sourceWatermarked, $identity);

            if ($this->isNoop($desiredOriginalImages, $sourceWatermarked, $identity)) {
                Log::info('BEM update media sync no-op: source images already match prod.watermarked', [
                    'source_shop' => $source->domain,
                    'source_product_id' => $this->sourceProductId,
                    'images_count' => count($desiredOriginalImages),
                ]);
                return;
            }

            Log::info('BEM update media sync started', [
                'source_shop' => $source->domain,
                'source_product_id' => $this->sourceProductId,
                'backup_shop' => $backup->backupShop->domain,
                'backup_product_gid' => $backup->sourceProductGid,
                'source_images' => count($sourceImages),
                'desired_images' => count($desiredOriginalImages),
            ]);

            if ($eligibility->isDryRun()) {
                Log::info('BEM update media sync dry-run completed without Shopify writes', [
                    'source_shop' => $source->domain,
                    'source_product_id' => $this->sourceProductId,
                    'desired_images' => count($desiredOriginalImages),
                ]);
                return;
            }

            $backupUploaded = $uploadService->replaceProductImagesFromUrls(
                $backup->backupShop,
                $backup->sourceProductGid,
                $desiredOriginalImages
            );
            $backupImages = $this->imagesFromBackupUpload($desiredOriginalImages, $backupUploaded);

            $sourceProcessed = $imageProcessor->process($source, $this->title, $desiredOriginalImages);
            $tempPaths = array_merge($tempPaths, $sourceProcessed['temp_paths']);
            $sourceUploaded = $uploadService->replaceProductImages(
                $source,
                $this->sourceProductGid,
                $sourceProcessed['processed']
            );
            $sourceFinalImages = $this->mergeUploadedImages($sourceProcessed['processed'], $sourceUploaded);

            $sourcePayload = $this->productWatermarkedPayload(
                sourceShop: $source,
                sourceProductGid: $this->sourceProductGid,
                sourceProductId: $this->sourceProductId,
                target: $source,
                targetProductGid: $this->sourceProductGid,
                targetProductId: $this->sourceProductId,
                images: $sourceFinalImages,
                mode: 'source_product_update'
            );
            $metafieldService->update($source, $this->sourceProductGid, $sourcePayload);

            $mirrors = ProductMirror::where('source_product_id', $this->sourceProductId)->get();
            $backupMirror = $mirrors->first(fn (ProductMirror $mirror) => (int) $mirror->target_shop_id === (int) $backup->backupShop->id);
            if ($backupMirror) {
                $this->updateMirrorSnapshot($backupMirror, $backupImages, $identity);
            }

            $targetMirrors = $this->targetMirrors($mirrors, $backup->backupShop);
            foreach ($targetMirrors as $mirror) {
                $target = Shop::find($mirror->target_shop_id);
                if (!$target || !$mirror->target_product_gid) {
                    continue;
                }

                $processed = $imageProcessor->process($target, $this->title, $backupImages);
                $tempPaths = array_merge($tempPaths, $processed['temp_paths']);
                $uploaded = $uploadService->replaceProductImages(
                    $target,
                    $mirror->target_product_gid,
                    $processed['processed']
                );
                $finalImages = $this->mergeUploadedImages($processed['processed'], $uploaded);

                $metafieldService->update($target, $mirror->target_product_gid, $this->productWatermarkedPayload(
                    sourceShop: $backup->backupShop,
                    sourceProductGid: $backup->sourceProductGid,
                    sourceProductId: (int) $backup->sourceProductId,
                    target: $target,
                    targetProductGid: $mirror->target_product_gid,
                    targetProductId: $mirror->target_product_id,
                    images: $finalImages,
                    mode: 'target_product_update'
                ));

                $this->updateMirrorSnapshot($mirror, $finalImages, $identity);
            }

            $manifest = $manifestService->fetch($backup->backupShop, $backup->sourceProductGid);
            $manifest['images'] = $this->manifestImages($desiredOriginalImages, $backupImages, $sourceFinalImages, $identity);
            $manifest = $manifestService->appendHistory($manifest, 'source_update_media_sync', [
                'source_shop' => $source->domain,
                'source_product_id' => $this->sourceProductId,
                'images_count' => count($manifest['images']),
                'target_count' => $targetMirrors->count(),
            ]);
            $manifestService->update($backup->backupShop, $backup->sourceProductGid, $manifest);

            Log::info('BEM update media sync completed', [
                'source_shop' => $source->domain,
                'source_product_id' => $this->sourceProductId,
                'backup_shop' => $backup->backupShop->domain,
                'images_count' => count($backupImages),
                'targets' => $targetMirrors->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('BEM update manifest sync attempt failed', [
                'source_shop' => $source->domain,
                'source_product_id' => $this->sourceProductId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $imageProcessor->cleanup($tempPaths);
            optional($lock)->release();
        }
    }

    public function failed(\Throwable $e): void
    {
        $source = Shop::find($this->sourceShopId);
        $context = [
            'target_shop_id' => $this->sourceShopId,
            'target_shop' => $source?->domain,
            'source_shop_id' => $this->sourceShopId,
            'source_product_id' => $this->sourceProductId,
            'target_product_id' => $this->sourceProductId,
            'target_product_gid' => $this->sourceProductGid,
            'mode' => 'source_update_manifest',
            'failed_callback' => true,
            'error' => $e->getMessage(),
        ];

        Log::error('BEM update manifest sync failed', $context);

        $email = (string) config('features.bem_watermark_sync.notification_email');
        if ($email === '') {
            return;
        }

        try {
            Mail::to($email)->send(new BemWatermarkFailedMail($context));
        } catch (\Throwable $mailException) {
            Log::error('BEM update manifest failure email failed', [
                'error' => $mailException->getMessage(),
                'context' => $context,
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sourceImages(BemImageIdentityService $identity): array
    {
        $images = [];

        foreach (($this->sourcePayload['images'] ?? []) as $index => $image) {
            $url = $image['src'] ?? null;
            if (!$url) {
                continue;
            }

            $images[] = [
                'position' => $index + 1,
                'url' => $url,
                'media_gid' => $image['admin_graphql_api_id'] ?? ($image['id'] ?? null),
                'alt' => $image['alt'] ?? ($this->title ?: null),
                'filename' => $identity->filenameFromUrl($url),
                'original_extension' => $identity->extensionFromUrl($url),
            ];
        }

        usort($images, static fn ($a, $b) => ((int) $a['position']) <=> ((int) $b['position']));

        return $images;
    }

    private function fetchSourceWatermarked(BemShopifyGraphqlClient $graphql, Shop $source): array
    {
        $query = <<<'GQL'
        query BemUpdateSourceWatermarked($id: ID!) {
          product(id: $id) {
            metafield(namespace: "prod", key: "watermarked") {
              value
            }
          }
        }
        GQL;

        $response = $graphql->request($source, $query, ['id' => $this->sourceProductGid]);
        $value = $response['data']['product']['metafield']['value'] ?? null;
        $decoded = is_string($value) ? json_decode($value, true) : null;
        if (!is_array($decoded) || empty($decoded['images'])) {
            throw new \RuntimeException('BEM source prod.watermarked history missing for update');
        }

        return $decoded;
    }

    /**
     * @param array<int, array<string, mixed>> $sourceImages
     * @return array<int, array<string, mixed>>
     */
    private function desiredOriginalImages(array $sourceImages, array $sourceWatermarked, BemImageIdentityService $identity): array
    {
        $history = (array) ($sourceWatermarked['images'] ?? []);
        $byWatermarkedUrl = [];
        $byFilename = [];

        foreach ($history as $image) {
            $url = $identity->canonicalUrl($image['watermarked_url'] ?? null);
            if ($url) {
                $byWatermarkedUrl[$url] = $image;
            }

            foreach ([
                $image['filename'] ?? null,
                $identity->filenameFromUrl($image['watermarked_url'] ?? null),
            ] as $filename) {
                if ($filename) {
                    $byFilename[strtolower($filename)] = $image;
                }
            }
        }

        $desired = [];
        foreach ($sourceImages as $index => $sourceImage) {
            $sourceUrl = (string) ($sourceImage['url'] ?? '');
            $filename = $identity->filenameFromUrl($sourceUrl);
            $historyImage = $byWatermarkedUrl[$identity->canonicalUrl($sourceUrl)] ?? null;
            if (!$historyImage && $filename) {
                $historyImage = $byFilename[strtolower($filename)] ?? null;
            }

            if ($historyImage) {
                if (empty($historyImage['source_url'])) {
                    throw new \RuntimeException('BEM source history image is missing source_url');
                }

                $desired[] = [
                    'position' => $index + 1,
                    'source_url' => $historyImage['source_url'],
                    'source_watermarked_url' => $sourceUrl,
                    'source_watermarked_filename' => $filename,
                    'previous_position' => $historyImage['position'] ?? null,
                    'original_extension' => $historyImage['original_extension']
                        ?? $identity->extensionFromUrl($historyImage['source_url'] ?? null),
                    'alt' => $sourceImage['alt'] ?? $this->title,
                    'status' => 'completed',
                    'matched_existing' => true,
                ];
                continue;
            }

            if ($identity->isWatermarkedUrl($sourceUrl)) {
                throw new \RuntimeException('BEM source update contains unknown watermarked image: '.$sourceUrl);
            }

            $desired[] = [
                'position' => $index + 1,
                'source_url' => $sourceUrl,
                'source_watermarked_url' => null,
                'source_watermarked_filename' => null,
                'previous_position' => null,
                'original_extension' => $sourceImage['original_extension'] ?? $identity->extensionFromUrl($sourceUrl),
                'alt' => $sourceImage['alt'] ?? $this->title,
                'status' => 'completed',
                'matched_existing' => false,
            ];
        }

        if (empty($desired)) {
            throw new \RuntimeException('BEM source update has no desired images');
        }

        return $desired;
    }

    private function isNoop(array $desiredOriginalImages, array $sourceWatermarked, BemImageIdentityService $identity): bool
    {
        $history = array_values((array) ($sourceWatermarked['images'] ?? []));
        if (count($history) !== count($desiredOriginalImages)) {
            return false;
        }

        foreach ($desiredOriginalImages as $index => $desired) {
            $historyImage = $history[$index] ?? null;
            if (!$historyImage) {
                return false;
            }

            $sourceMatches = $identity->canonicalUrl($desired['source_url'] ?? null)
                === $identity->canonicalUrl($historyImage['source_url'] ?? null);
            $watermarkMatches = $identity->canonicalUrl($desired['source_watermarked_url'] ?? null)
                === $identity->canonicalUrl($historyImage['watermarked_url'] ?? null);

            if (!$sourceMatches || !$watermarkMatches) {
                return false;
            }
        }

        return true;
    }

    private function imagesFromBackupUpload(array $desiredOriginalImages, array $backupUploaded): array
    {
        $images = [];
        foreach ($desiredOriginalImages as $index => $desired) {
            $uploaded = $backupUploaded[$index] ?? [];
            $backupUrl = $uploaded['uploaded_url'] ?? null;
            if (!$backupUrl) {
                throw new \RuntimeException('BEM backup update did not return image URL at index '.$index);
            }

            $images[] = [
                'position' => $desired['position'],
                'source_url' => $backupUrl,
                'original_source_url' => $desired['source_url'],
                'original_extension' => $desired['original_extension'],
                'alt' => $desired['alt'] ?? null,
                'status' => 'completed',
            ];
        }

        return $images;
    }

    private function productWatermarkedPayload(
        Shop $sourceShop,
        string $sourceProductGid,
        int $sourceProductId,
        Shop $target,
        string $targetProductGid,
        ?int $targetProductId,
        array $images,
        string $mode
    ): array {
        return [
            'status' => 'completed',
            'mode' => $mode,
            'source_shop' => $sourceShop->domain,
            'source_product_id' => $sourceProductId,
            'source_product_gid' => $sourceProductGid,
            'target_shop' => $target->domain,
            'target_product_id' => $targetProductId,
            'target_product_gid' => $targetProductGid,
            'updated_at' => now()->toIso8601String(),
            'dry_run' => false,
            'images' => $this->metafieldImages($images),
        ];
    }

    private function mergeUploadedImages(array $processedImages, array $uploadedImages): array
    {
        $uploadedByPosition = [];
        foreach ($uploadedImages as $uploaded) {
            $uploadedByPosition[(int) ($uploaded['position'] ?? 0)] = $uploaded;
        }

        foreach ($processedImages as $index => $processed) {
            $position = (int) ($processed['position'] ?? 0);
            if (isset($uploadedByPosition[$position])) {
                $processedImages[$index] = array_merge($processed, $uploadedByPosition[$position]);
            }
        }

        return $processedImages;
    }

    private function metafieldImages(array $images): array
    {
        return array_values(array_map(static fn ($image) => [
            'position' => $image['position'] ?? null,
            'source_url' => $image['source_url'] ?? null,
            'watermarked_url' => $image['watermarked_url'] ?? null,
            'filename' => $image['filename'] ?? null,
            'original_extension' => $image['original_extension'] ?? null,
            'status' => $image['status'] ?? null,
        ], $images));
    }

    private function targetMirrors($mirrors, Shop $backupShop)
    {
        $allowedDomains = array_values(array_filter(array_map(
            'strtolower',
            (array) config('features.bem_watermark_sync.target_shop_domains')
        )));

        if (empty($allowedDomains)) {
            $allowedDomains = array_values(array_filter(
                array_map('strtolower', array_keys((array) config('features.bem_watermark_sync.domain_aliases'))),
                static fn ($domain) => $domain !== strtolower((string) config('features.bem_watermark_sync.backup_shop_domain'))
            ));
        }

        return $mirrors->filter(function (ProductMirror $mirror) use ($backupShop, $allowedDomains) {
            if ((int) $mirror->target_shop_id === (int) $backupShop->id) {
                return false;
            }

            $shop = Shop::find($mirror->target_shop_id);
            if (!$shop || !$mirror->target_product_gid) {
                return false;
            }

            return in_array(strtolower((string) $shop->domain), $allowedDomains, true);
        })->values();
    }

    private function updateMirrorSnapshot(ProductMirror $mirror, array $images, BemImageIdentityService $identity): void
    {
        $snapshot = $mirror->last_snapshot ?? [];
        $snapshotImages = [];
        foreach (array_values($images) as $index => $image) {
            $url = $image['watermarked_url'] ?? $image['uploaded_url'] ?? $image['source_url'] ?? null;
            $snapshotImages[] = [
                'src' => $url,
                'src_canon' => $identity->canonicalUrl($url),
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

    private function manifestImages(
        array $desiredOriginalImages,
        array $backupImages,
        array $sourceFinalImages,
        BemImageIdentityService $identity
    ): array {
        $manifestImages = [];

        foreach ($desiredOriginalImages as $index => $desired) {
            $backupImage = $backupImages[$index] ?? [];
            $sourceFinal = $sourceFinalImages[$index] ?? [];
            $sourceUrl = $desired['source_url'] ?? null;
            $uuid = 'bem_'.substr(sha1((string) $identity->canonicalUrl($sourceUrl)), 0, 26);

            $manifestImages[] = [
                'image_uuid' => $uuid,
                'status' => 'active',
                'position' => $index + 1,
                'source_original_url' => $sourceUrl,
                'backup_url' => $backupImage['source_url'] ?? null,
                'source_watermarked_url' => $sourceFinal['watermarked_url'] ?? null,
                'source_watermarked_filename' => $sourceFinal['filename'] ?? null,
                'original_extension' => $desired['original_extension'] ?? null,
                'updated_at' => now()->toIso8601String(),
            ];
        }

        return $manifestImages;
    }

    private function lockKey(Shop $source): string
    {
        return sprintf(
            'bem-update-manifest:%s:%s',
            Str::slug((string) $source->domain),
            Str::afterLast($this->sourceProductGid, '/')
        );
    }
}
