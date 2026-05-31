<?php

namespace App\Jobs;

use App\Mail\BemWatermarkFailedMail;
use App\Models\Shop;
use App\Services\Shopify\BemWatermark\BemBackupManifestService;
use App\Services\Shopify\BemWatermark\BemBackupProductImageResolver;
use App\Services\Shopify\BemWatermark\BemSourceUpdateImageClassifier;
use App\Services\Shopify\BemWatermark\BemWatermarkEligibilityService;
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
        BemSourceUpdateImageClassifier $classifier
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

            $manifest = $manifestService->fetch($backup->backupShop, $backup->sourceProductGid);
            $result = $classifier->classify($this->sourceImages(), $manifest);

            if (!empty($result['unknown_watermarked'])) {
                throw new \RuntimeException('BEM update manifest found unknown watermarked source images');
            }

            Log::info('BEM update manifest classified source images', [
                'source_shop' => $source->domain,
                'source_product_id' => $this->sourceProductId,
                'backup_shop' => $backup->backupShop->domain,
                'backup_product_gid' => $backup->sourceProductGid,
                'existing' => count($result['existing']),
                'new_clean' => count($result['new_clean']),
                'deleted' => count($result['deleted']),
                'desired_order' => $result['desired_order'],
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
    private function sourceImages(): array
    {
        $images = [];

        foreach (($this->sourcePayload['images'] ?? []) as $index => $image) {
            $url = $image['src'] ?? null;
            if (!$url) {
                continue;
            }

            $images[] = [
                'position' => (int) ($image['position'] ?? ($index + 1)),
                'url' => $url,
                'media_gid' => $image['admin_graphql_api_id'] ?? ($image['id'] ?? null),
                'alt' => $image['alt'] ?? ($this->title ?: null),
            ];
        }

        usort($images, static fn ($a, $b) => ((int) $a['position']) <=> ((int) $b['position']));

        return $images;
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
