<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Models\ShopConnection;
use App\Models\ShopifyWebhookEvent;
use App\Jobs\CoordinateSourceWatermark;
use App\Models\ProductMediaProcess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\ProductMirror;
use App\Jobs\ReplicateProductUpdateToShop;
use App\Jobs\ReplicateStockOnlyToShop8;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Services\Shopify\ProductImagesBackupService;

class ProcessShopifyWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = [10, 30, 60, 120];

    public function __construct(
        public string $topic,
        public ?string $shopDomain,
        public array $payload,
        public ?int $eventId = null,
    ) {}

    public function handle(): void
    {
        // (opțional) salvează webhookul în logs table dacă folosești ShopifyWebhookEvent
        if ($this->eventId === null) {
            ShopifyWebhookEvent::create([
                'webhook_id'  => request()?->header('X-Shopify-Webhook-Id'),
                'topic'       => $this->topic,
                'shop_domain' => $this->shopDomain,
                'payload'     => $this->payload,
            ]);
        }

        $sourceShop = Shop::where('domain', $this->shopDomain)->where('is_source', true)->first();
        if (!$sourceShop) return;

        // Log::info('ProcessShopifyWebhook payload', [
        //     'topic' => $this->topic,
        //     'shop'  => $this->shopDomain,
        //     'payload' => $this->payload,
        // ]);

        match ($this->topic) {
            'products/create' => $this->fanOutCreate($sourceShop, $this->payload),
            'products/update' => $this->fanOutUpdate($sourceShop, $this->payload),
            default => null,
        };
    }

    protected function fanOutCreate(Shop $sourceShop, array $payload): void
    {
        $sourceProductId = (int)($payload['id'] ?? 0);
        if (!$sourceProductId) return;

        $this->backupSourceImages($sourceShop, $payload);

        $sourceImages = $this->extractImagesFromPayload($payload);
        $sourceProductGid = $payload['admin_graphql_api_id'] ?? "gid://shopify/Product/{$sourceProductId}";

        ProductMediaProcess::updateOrCreate(
            [
                'shop_domain' => $sourceShop->domain,
                'product_id' => $sourceProductId,
            ],
            [
                'shop_id' => $sourceShop->id,
                'product_gid' => $sourceProductGid,
                'status' => ProductMediaProcess::STATUS_PENDING,
                'images_count' => count($sourceImages),
                'processed_count' => 0,
                'last_error' => null,
                'started_at' => null,
                'completed_at' => null,
            ]
        );

        $targets = ShopConnection::where('source_shop_id', $sourceShop->id)
            ->with('target')->get()->pluck('target')->filter(fn($s) => $s->is_active);

        foreach ($targets as $target) {
            if ((int)$target->id === 7 || $target->domain === 'iluminat-industrial.myshopify.com') {
                Log::info('fanOutCreate: skipping Industrial target for auto-create', [
                    'product_id' => $sourceProductId,
                    'target_shop' => $target->domain,
                ]);
                continue;
            }

            \App\Jobs\ReplicateProductCreateToShop::dispatch(
                $target->id,
                $sourceShop->id,
                $sourceProductId,
                $payload
            )->onQueue('replication');
        }

        // CoordinateSourceWatermark::dispatch(
        //     sourceShopId: $sourceShop->id,
        //     sourceProductId: $sourceProductId,
        //     payload: $payload
        // )->delay(now()->addSeconds(60))
        //  ->onQueue('watermarks');
    }

    protected function fanOutUpdate(Shop $sourceShop, array $payload): void
    {
        $sourceProductId = (int)($payload['id'] ?? 0);
        if (!$sourceProductId) {
            \Log::warning('fanOutUpdate: missing source product id', ['shop' => $sourceShop->domain]);
            return;
        }

        $this->backupSourceImages($sourceShop, $payload);

        // 1) Doar pentru magazinul sursă: setează metafieldul custom.trigger=false ca să previi bucle
        $productGid = "gid://shopify/Product/{$sourceProductId}";
        try {
            $this->setTriggerMetafieldFalse($sourceShop, $productGid);
        } catch (\Throwable $e) {
            \Log::warning('setTrigger=false failed on source', ['shop' => $sourceShop->domain, 'product' => $sourceProductId, 'error' => $e->getMessage()]);
        }

        // Resetăm și store.update_industrial la false după fiecare update
        try {
            $this->setIndustrialFlagFalse($sourceShop, $productGid);
        } catch (\Throwable $e) {
            \Log::warning('setIndustrialFlag=false failed on source', ['shop' => $sourceShop->domain, 'product' => $sourceProductId, 'error' => $e->getMessage()]);
        }

        $targets = \App\Models\ShopConnection::where('source_shop_id', $sourceShop->id)
            ->with('target')->get()->pluck('target')->filter(fn($s) => $s && $s->is_active);

        // Dacă metafieldul store.update_industrial este true, adaugă forțat shop-ul Industrial (id=7)
        if ($this->shouldUpdateIndustrial($this->payload)) {
            $industrial = Shop::find(7);
            if ($industrial && $industrial->is_active && $targets->where('id', $industrial->id)->isEmpty()) {
                $targets->push($industrial);
                \Log::info('fanOutUpdate: forced Industrial target via metafield', [
                    'product_id' => $sourceProductId,
                    'target_shop' => $industrial->domain,
                ]);
            }
        }

        foreach ($targets as $target) {
            if ((int)$target->id === 8) {
                ReplicateStockOnlyToShop8::dispatch(
                    targetShopId: $target->id,
                    sourceShopId: $sourceShop->id,
                    sourceProductId: $sourceProductId,
                    payload: $payload
                )->onQueue('replication');
                continue;
            }

            ReplicateProductUpdateToShop::dispatch(
                targetShopId: $target->id,
                sourceShopId: $sourceShop->id,
                sourceProductId: $sourceProductId,
                payload: $payload
            )->onQueue('replication');
        }

        \Log::info('fanOutUpdate queued', [
            'source_shop' => $sourceShop->domain,
            'targets'     => $targets->pluck('domain')->values(),
            'product_id'  => $sourceProductId,
        ]);
    }

    private function shouldUpdateIndustrial(array $payload): bool
    {
        $metafields = $payload['metafields'] ?? [];
        if (!is_array($metafields)) {
            return false;
        }

        foreach ($metafields as $meta) {
            $namespace = $meta['namespace'] ?? null;
            $key       = $meta['key'] ?? null;
            if ($namespace !== 'store' || $key !== 'update_industrial') {
                continue;
            }

            $value = $meta['value'] ?? null;
            if (is_bool($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return (int)$value === 1;
            }
            if (is_string($value)) {
                $normalized = strtolower(trim($value));
                return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
            }
        }

        return false;
    }

    private function backupSourceImages(Shop $shop, array $payload): void
    {
        $productId = (int)($payload['id'] ?? 0);
        $productGid = $payload['admin_graphql_api_id'] ?? ($productId ? "gid://shopify/Product/{$productId}" : null);

        if (!$productGid) {
            return;
        }

        $images = $this->extractImagesFromPayload($payload);
        if (empty($images)) {
            return;
        }

        ProductImagesBackupService::syncFromImages($shop, $productGid, $images);
    }

    /**
     * @return array<int, array{position:int,url:string}>
     */
    private function extractImagesFromPayload(array $payload): array
    {
        $out = [];

        if (!empty($payload['images']) && is_array($payload['images'])) {
            foreach ($payload['images'] as $index => $img) {
                $url = $img['src'] ?? null;
                if (!$url) {
                    continue;
                }

                $out[] = [
                    'position' => (int)($img['position'] ?? ($index + 1)),
                    'url' => $url,
                ];
            }
        } elseif (!empty($payload['media']) && is_array($payload['media'])) {
            foreach ($payload['media'] as $index => $entry) {
                if (($entry['media_content_type'] ?? '') !== 'IMAGE') {
                    continue;
                }

                $url = $entry['preview_image']['src'] ?? null;
                if (!$url) {
                    continue;
                }

                $out[] = [
                    'position' => (int)($entry['position'] ?? ($index + 1)),
                    'url' => $url,
                ];
            }
        }

        usort($out, fn ($a, $b) => $a['position'] <=> $b['position']);

        return $out;
    }

    /**
     * Source-only: set custom.trigger=false via GraphQL (2025-01).
     */
    private function setTriggerMetafieldFalse(Shop $shop, string $productGid): void
    {
        $this->setBooleanMetafield($shop, $productGid, 'dont', 'trigger2', false, 'setTrigger=false');
    }

    private function setIndustrialFlagFalse(Shop $shop, string $productGid): void
    {
        $this->setBooleanMetafield($shop, $productGid, 'store', 'update_industrial', false, 'setIndustrialFlag=false');
    }

    private function setBooleanMetafield(Shop $shop, string $productGid, string $namespace, string $key, bool $value, string $logContext): void
    {
        $version  = $shop->api_version ?: '2025-01';
        $endpoint = "https://{$shop->domain}/admin/api/{$version}/graphql.json";

        $mutation = <<<'GQL'
        mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
          metafieldsSet(metafields: $metafields) {
            metafields { id namespace key value type }
            userErrors { field message }
          }
        }
        GQL;

        $variables = [
            'metafields' => [[
                'ownerId'  => $productGid,
                'namespace'=> $namespace,
                'key'      => $key,
                'type'     => 'boolean',
                'value'    => $value ? 'true' : 'false',
            ]]
        ];

        $resp = Http::withHeaders([
            'X-Shopify-Access-Token' => $shop->access_token,
            'Content-Type'           => 'application/json',
        ])->post($endpoint, ['query' => $mutation, 'variables' => $variables]);

        $body = $resp->json();
        if (!$resp->successful() || !empty($body['errors']) || !empty($body['data']['metafieldsSet']['userErrors'] ?? [])) {
            \Log::warning("{$logContext} metafieldsSet issues", [
                'shop' => $shop->domain,
                'status' => $resp->status(),
                'body' => $body,
            ]);
        } else {
            \Log::info("{$logContext} applied", ['shop' => $shop->domain, 'product' => $productGid]);
        }
    }

}
