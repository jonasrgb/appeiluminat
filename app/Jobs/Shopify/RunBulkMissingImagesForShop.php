<?php

namespace App\Jobs\Shopify;

use App\Models\Shop;
use App\Services\Shopify\BulkMissingImagesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunBulkMissingImagesForShop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $backoff = [15, 60];
    public $timeout = 1800;

    public function __construct(
        public int $shopId,
        public int $timeoutSeconds = 900,
        public int $pollSeconds = 5,
        public int $sampleLimit = 20,
    ) {}

    public function handle(BulkMissingImagesService $service): void
    {
        $shop = Shop::findOrFail($this->shopId);

        $result = $service->runForShop(
            shop: $shop,
            timeoutSeconds: $this->timeoutSeconds,
            pollSeconds: $this->pollSeconds,
            sampleLimit: $this->sampleLimit,
        );

        Log::info('Bulk missing images completed', $result);
    }
}
