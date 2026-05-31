<?php

namespace App\Jobs;

use App\Mail\BemWatermarkFailedMail;
use App\Models\Shop;
use App\Services\Shopify\BemWatermark\BemBackupProductImageResolver;
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

class BemApplySourceProductWatermark implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;
    public int $timeout = 900;
    public array $backoff = [60, 120, 180, 300, 300];

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
        BemWatermarkImageProcessor $imageProcessor,
        BemShopifyStagedUploadService $uploadService,
        BemProductWatermarkMetafieldService $metafieldService,
        BemShopifyGraphqlClient $graphql
    ): void {
        $source = Shop::findOrFail($this->sourceShopId);

        if (!$eligibility->isEligiblePayloadForSource($this->sourcePayload, $source)) {
            Log::info('BEM source watermark skipped: source payload not eligible', [
                'source_shop' => $source->domain,
                'source_product_id' => $this->sourceProductId,
            ]);
            return;
        }

        $images = $this->sourceImages();
        if (empty($images)) {
            Log::info('BEM source watermark skipped: product create payload has no images', [
                'source_shop' => $source->domain,
                'source_product_id' => $this->sourceProductId,
            ]);
            return;
        }

        if ($this->alreadyCompleted($source, $graphql)) {
            Log::info('BEM source watermark skipped: prod.watermarked already completed', [
                'source_shop' => $source->domain,
                'source_product_id' => $this->sourceProductId,
            ]);
            return;
        }

        $lock = Cache::lock($this->lockKey($source), 900);
        if (!$lock->get()) {
            Log::info('BEM source watermark waiting for lock', [
                'source_shop' => $source->domain,
                'source_product_gid' => $this->sourceProductGid,
            ]);
            $this->release(30);
            return;
        }

        $tempPaths = [];

        try {
            $backupImages = $backupResolver->resolve($this->sourceShopId, $this->sourceProductId);
            if (!$backupImages->ready || count($backupImages->images) < count($images)) {
                $this->handleBackupNotReady($source, $backupImages->reason, count($images), count($backupImages->images));
                return;
            }

            $originalMediaIds = $uploadService->fetchProductImageMediaIds($source, $this->sourceProductGid);
            if (count($originalMediaIds) < count($images)) {
                throw new \RuntimeException('BEM source watermark original media ids not ready');
            }

            $processedResult = $imageProcessor->process($source, $this->title, $images);
            $processedImages = $processedResult['processed'];
            $tempPaths = $processedResult['temp_paths'];

            if ($eligibility->isDryRun()) {
                Log::info('BEM source watermark dry-run completed without Shopify writes', [
                    'source_shop' => $source->domain,
                    'source_product_gid' => $this->sourceProductGid,
                    'images' => $this->metafieldImages($processedImages),
                ]);
                return;
            }

            $uploadedImages = $uploadService->appendProductImages($source, $this->sourceProductGid, $processedImages);
            if (count($uploadedImages) !== count($images)) {
                throw new \RuntimeException('BEM source watermark uploaded image count mismatch');
            }

            $this->waitForAppendedImages($source, $uploadService, count($originalMediaIds) + count($uploadedImages));

            $uploadService->deleteProductMedia($source, $this->sourceProductGid, $originalMediaIds);
            $finalImages = $uploadService->applyFinalProductImageUrls(
                $source,
                $this->sourceProductGid,
                $this->mergeUploadedImages($processedImages, $uploadedImages)
            );

            $metafieldService->update($source, $this->sourceProductGid, [
                'status' => 'completed',
                'mode' => 'source_product_create',
                'source_shop' => $source->domain,
                'source_product_id' => $this->sourceProductId,
                'source_product_gid' => $this->sourceProductGid,
                'target_shop' => $source->domain,
                'target_product_id' => $this->sourceProductId,
                'target_product_gid' => $this->sourceProductGid,
                'backup_shop' => $backupImages->backupShop?->domain,
                'backup_product_id' => $backupImages->sourceProductId,
                'backup_product_gid' => $backupImages->sourceProductGid,
                'updated_at' => now()->toIso8601String(),
                'dry_run' => false,
                'images' => $this->metafieldImages($finalImages),
            ]);

            Log::info('BEM source watermark job completed', [
                'source_shop' => $source->domain,
                'source_product_gid' => $this->sourceProductGid,
                'images_count' => count($finalImages),
            ]);
        } catch (\Throwable $e) {
            Log::error('BEM source watermark job attempt failed', [
                'source_shop' => $source->domain,
                'source_product_id' => $this->sourceProductId,
                'source_product_gid' => $this->sourceProductGid,
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
            'mode' => 'source_product_create',
            'failed_callback' => true,
            'error' => $e->getMessage(),
        ];

        Log::error('BEM source watermark job failed', $context);

        $email = (string) config('features.bem_watermark_sync.notification_email');
        if ($email === '') {
            return;
        }

        try {
            Mail::to($email)->send(new BemWatermarkFailedMail($context));
        } catch (\Throwable $mailException) {
            Log::error('BEM source watermark failure email failed', [
                'error' => $mailException->getMessage(),
                'context' => $context,
            ]);
        }
    }

    private function handleBackupNotReady(Shop $source, ?string $reason, int $expectedImages, int $backupImages): void
    {
        Log::warning('BEM source watermark waiting for backup product', [
            'source_shop' => $source->domain,
            'source_product_id' => $this->sourceProductId,
            'attempt' => $this->attempts(),
            'reason' => $reason,
            'expected_images' => $expectedImages,
            'backup_images' => $backupImages,
        ]);

        if ($this->attempts() >= $this->tries) {
            throw new \RuntimeException('BEM source watermark backup product not ready: '.($reason ?: 'image_count_mismatch'));
        }

        $this->release(60);
    }

    private function alreadyCompleted(Shop $source, BemShopifyGraphqlClient $graphql): bool
    {
        $query = <<<'GQL'
        query BemSourceWatermarkedMetafield($id: ID!) {
          product(id: $id) {
            metafield(namespace: "prod", key: "watermarked") {
              value
            }
          }
        }
        GQL;

        $response = $graphql->request($source, $query, ['id' => $this->sourceProductGid]);
        $value = $response['data']['product']['metafield']['value'] ?? null;
        if (!$value) {
            return false;
        }

        $payload = json_decode((string) $value, true);
        if (!is_array($payload)) {
            return false;
        }

        return ($payload['status'] ?? null) === 'completed'
            || ($payload['mode'] ?? null) === 'source_product_create';
    }

    private function waitForAppendedImages(Shop $source, BemShopifyStagedUploadService $uploadService, int $expectedCount): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $images = $uploadService->fetchProductImages($source, $this->sourceProductGid);
            if (count($images) >= $expectedCount) {
                return;
            }

            sleep(2);
        }

        throw new \RuntimeException('BEM source watermark appended media not ready');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sourceImages(): array
    {
        $images = [];

        foreach (($this->sourcePayload['images'] ?? []) as $index => $image) {
            $sourceUrl = $image['src'] ?? null;
            if (!$sourceUrl) {
                continue;
            }

            $images[] = [
                'position' => (int) ($image['position'] ?? ($index + 1)),
                'source_url' => $sourceUrl,
                'image_id' => $image['admin_graphql_api_id'] ?? ($image['id'] ?? null),
                'alt' => $image['alt'] ?? ($this->title ?: null),
                'original_extension' => $this->extensionFromUrl($sourceUrl),
            ];
        }

        usort($images, static fn ($a, $b) => ((int) $a['position']) <=> ((int) $b['position']));

        return $images;
    }

    private function extensionFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension !== '' ? $extension : 'jpg';
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

    private function lockKey(Shop $source): string
    {
        return sprintf(
            'bem-source-watermark:%s:%s',
            Str::slug((string) $source->domain),
            Str::afterLast($this->sourceProductGid, '/')
        );
    }
}
