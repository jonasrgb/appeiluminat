<?php

namespace App\Jobs;

use App\Mail\BemWatermarkFailedMail;
use App\Models\Shop;
use App\Services\Shopify\BemWatermark\BemBackupProductImageResolver;
use App\Services\Shopify\BemWatermark\BemProductWatermarkMetafieldService;
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

class BemApplyProductWatermark implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;
    public int $timeout = 900;
    public array $backoff = [60, 120, 180, 300, 300];

    public function __construct(
        public int $targetShopId,
        public int $sourceShopId,
        public int $sourceProductId,
        public string $targetProductGid,
        public ?int $targetProductId,
        public string $title,
        public array $sourcePayload
    ) {
    }

    public function handle(
        BemWatermarkEligibilityService $eligibility,
        BemBackupProductImageResolver $backupResolver,
        BemWatermarkImageProcessor $imageProcessor,
        BemShopifyStagedUploadService $uploadService,
        BemProductWatermarkMetafieldService $metafieldService
    ): void {
        $target = Shop::findOrFail($this->targetShopId);

        if (!$eligibility->isEligiblePayloadForTarget($this->sourcePayload, $target)) {
            Log::info('BEM watermark skipped: target or payload no longer eligible', [
                'target_shop' => $target->domain,
                'source_product_id' => $this->sourceProductId,
            ]);
            return;
        }

        $lock = Cache::lock($this->lockKey($target), 900);
        if (!$lock->get()) {
            Log::info('BEM watermark waiting for lock', [
                'target_shop' => $target->domain,
                'target_product_gid' => $this->targetProductGid,
            ]);
            $this->release(30);
            return;
        }

        $tempPaths = [];

        try {
            Log::info('BEM watermark job started', [
                'target_shop' => $target->domain,
                'target_product_gid' => $this->targetProductGid,
                'source_shop_id' => $this->sourceShopId,
                'source_product_id' => $this->sourceProductId,
                'dry_run' => $eligibility->isDryRun(),
            ]);

            $backupImages = $backupResolver->resolve($this->sourceShopId, $this->sourceProductId);
            if (!$backupImages->ready) {
                $this->handleBackupNotReady($target, $backupImages->reason);
                return;
            }

            $processedResult = $imageProcessor->process($target, $this->title, $backupImages->images);
            $processedImages = $processedResult['processed'];
            $tempPaths = $processedResult['temp_paths'];

            if ($eligibility->isDryRun()) {
                Log::info('BEM watermark dry-run completed without Shopify writes', [
                    'target_shop' => $target->domain,
                    'target_product_gid' => $this->targetProductGid,
                    'images' => $this->metafieldImages($processedImages),
                ]);
                return;
            }

            if (!$eligibility->isEligiblePayloadForTarget($this->sourcePayload, $target)) {
                Log::warning('BEM watermark destructive step skipped: eligibility failed on final check', [
                    'target_shop' => $target->domain,
                    'target_product_gid' => $this->targetProductGid,
                ]);
                return;
            }

            $uploadedImages = $uploadService->replaceProductImages(
                $target,
                $this->targetProductGid,
                $processedImages
            );

            $summary = $this->buildMetafieldPayload(
                target: $target,
                backupShop: $backupImages->backupShop,
                backupSourceProductId: $backupImages->sourceProductId,
                backupSourceProductGid: $backupImages->sourceProductGid,
                images: $this->mergeUploadedImages($processedImages, $uploadedImages),
                dryRun: false
            );

            $metafieldService->update($target, $this->targetProductGid, $summary);

            Log::info('BEM watermark job completed', [
                'target_shop' => $target->domain,
                'target_product_gid' => $this->targetProductGid,
                'images_count' => count($summary['images']),
            ]);
        } catch (\Throwable $e) {
            Log::error('BEM watermark job attempt failed', [
                'target_shop' => $target->domain,
                'source_product_id' => $this->sourceProductId,
                'target_product_gid' => $this->targetProductGid,
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
        $target = Shop::find($this->targetShopId);
        $this->notifyFailure($target, $e, true);
    }

    private function handleBackupNotReady(Shop $target, ?string $reason): void
    {
        Log::warning('BEM watermark backup product not ready', [
            'target_shop' => $target->domain,
            'source_shop_id' => $this->sourceShopId,
            'source_product_id' => $this->sourceProductId,
            'attempt' => $this->attempts(),
            'reason' => $reason,
        ]);

        if ($this->attempts() >= $this->tries) {
            throw new \RuntimeException('BEM backup product not ready: '.($reason ?: 'unknown'));
        }

        $this->release(60);
    }

    private function buildMetafieldPayload(
        Shop $target,
        ?Shop $backupShop,
        ?int $backupSourceProductId,
        ?string $backupSourceProductGid,
        array $images,
        bool $dryRun
    ): array {
        return [
            'source_shop' => $backupShop?->domain,
            'source_product_id' => $backupSourceProductId,
            'source_product_gid' => $backupSourceProductGid,
            'target_shop' => $target->domain,
            'target_product_id' => $this->targetProductId,
            'target_product_gid' => $this->targetProductGid,
            'updated_at' => now()->toIso8601String(),
            'dry_run' => $dryRun,
            'images' => $this->metafieldImages($images),
        ];
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

    private function notifyFailure(?Shop $target, \Throwable $e, bool $failedCallback = false): void
    {
        $context = [
            'target_shop_id' => $this->targetShopId,
            'target_shop' => $target?->domain,
            'source_shop_id' => $this->sourceShopId,
            'source_product_id' => $this->sourceProductId,
            'target_product_id' => $this->targetProductId,
            'target_product_gid' => $this->targetProductGid,
            'failed_callback' => $failedCallback,
            'error' => $e->getMessage(),
        ];

        Log::error('BEM watermark job failed', $context);

        $email = (string) config('features.bem_watermark_sync.notification_email');
        if ($email === '') {
            return;
        }

        try {
            Mail::to($email)->send(new BemWatermarkFailedMail($context));
        } catch (\Throwable $mailException) {
            Log::error('BEM watermark failure email failed', [
                'error' => $mailException->getMessage(),
                'context' => $context,
            ]);
        }
    }

    private function lockKey(Shop $target): string
    {
        return sprintf(
            'bem-watermark:%s:%s',
            Str::slug((string) $target->domain),
            Str::afterLast($this->targetProductGid, '/')
        );
    }
}
