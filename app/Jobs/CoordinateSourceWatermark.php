<?php

namespace App\Jobs;

use App\Models\ProductMediaProcess;
use App\Models\ProductMirror;
use App\Models\Shop;
use App\Models\ShopConnection;
use App\Services\Shopify\ProductWebhookPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CoordinateSourceWatermark implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;
    public int $backoff = 60;

    public function __construct(
        public int $sourceShopId,
        public int $sourceProductId,
        public array $payload
    ) {}

    public function handle(): void
    {
        $sourceShop = Shop::find($this->sourceShopId);
        if (!$sourceShop) {
            Log::warning('CoordinateSourceWatermark missing source shop', [
                'source_shop_id' => $this->sourceShopId,
                'product_id' => $this->sourceProductId,
            ]);
            return;
        }

        $targets = ShopConnection::where('source_shop_id', $sourceShop->id)
            ->with('target')
            ->get()
            ->pluck('target')
            ->filter(fn ($shop) => $shop && $shop->is_active)
            ->values();

        if ($targets->isEmpty()) {
            Log::info('CoordinateSourceWatermark no active targets, dispatching immediately', [
                'shop' => $sourceShop->domain,
                'product_id' => $this->sourceProductId,
            ]);
            $this->dispatchSourcePipeline($sourceShop);
            return;
        }

        $pending = [];
        foreach ($targets as $target) {
            if ($this->shouldIgnoreTarget($target->domain ?? '')) {
                continue;
            }
            $mirror = ProductMirror::where([
                'source_shop_id'    => $sourceShop->id,
                'source_product_id' => $this->sourceProductId,
                'target_shop_id'    => $target->id,
            ])->first();

            if (!$mirror) {
                $pending[] = [
                    'shop' => $target->domain,
                    'reason' => 'mirror_missing',
                ];
                continue;
            }

            $process = ProductMediaProcess::where('shop_domain', $target->domain)
                ->where('product_id', $mirror->target_product_id)
                ->first();

            if (!$process) {
                $pending[] = [
                    'shop' => $target->domain,
                    'reason' => 'process_missing',
                ];
                continue;
            }

            if ($process->status !== ProductMediaProcess::STATUS_COMPLETED) {
                if (in_array($process->status, [
                    ProductMediaProcess::STATUS_FAILED,
                    ProductMediaProcess::STATUS_SKIPPED,
                ], true)) {
                    Log::warning('CoordinateSourceWatermark detected target failure, continuing', [
                        'source_product_id' => $this->sourceProductId,
                        'target_shop' => $target->domain,
                        'status' => $process->status,
                    ]);
                    continue;
                }

                $pending[] = [
                    'shop' => $target->domain,
                    'reason' => "status_{$process->status}",
                ];
            }
        }

        if (!empty($pending)) {
            if ($this->attempts() >= $this->tries) {
                Log::warning('CoordinateSourceWatermark timeout, proceeding anyway', [
                    'shop' => $sourceShop->domain,
                    'product_id' => $this->sourceProductId,
                    'pending' => $pending,
                ]);
                $this->dispatchSourcePipeline($sourceShop);
                return;
            }

            Log::info('CoordinateSourceWatermark waiting for targets', [
                'shop' => $sourceShop->domain,
                'product_id' => $this->sourceProductId,
                'pending' => $pending,
                'attempt' => $this->attempts(),
            ]);
            $this->release($this->backoff);
            return;
        }

        Log::info('CoordinateSourceWatermark all targets ready', [
            'shop' => $sourceShop->domain,
            'product_id' => $this->sourceProductId,
        ]);

        $this->dispatchSourcePipeline($sourceShop);
    }

    private function dispatchSourcePipeline(Shop $sourceShop): void
    {
        ProductWebhookPipeline::dispatchImages(
            shopDomain: $sourceShop->domain,
            payload: $this->payload,
            delaySeconds: 30,
            queue: 'webhooks'
        );
    }

    private function shouldIgnoreTarget(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        $ignored = [
            'eiluminatbackup.myshopify.com',
        ];

        return in_array($domain, $ignored, true);
    }
}
