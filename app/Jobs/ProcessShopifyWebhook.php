<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Models\ShopConnection;
use App\Models\ShopifyWebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\ProductMirror;
use App\Jobs\ReplicateProductUpdateToShop;
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

        $targets = ShopConnection::where('source_shop_id', $sourceShop->id)
            ->with('target')->get()->pluck('target')->filter(fn($s) => $s->is_active);

        foreach ($targets as $target) {
            \App\Jobs\ReplicateProductCreateToShop::dispatch(
                $target->id,
                $sourceShop->id,
                $sourceProductId,
                $payload
            )->onQueue('replication');
        }
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
        try {
            $this->setTriggerMetafieldFalse($sourceShop, "gid://shopify/Product/{$sourceProductId}");
        } catch (\Throwable $e) {
            \Log::warning('setTrigger=false failed on source', ['shop' => $sourceShop->domain, 'product' => $sourceProductId, 'error' => $e->getMessage()]);
        }

        $targets = \App\Models\ShopConnection::where('source_shop_id', $sourceShop->id)
            ->with('target')->get()->pluck('target')->filter(fn($s) => $s && $s->is_active);

        foreach ($targets as $target) {
            \App\Jobs\ReplicateProductUpdateToShop::dispatch(
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
                'namespace'=> 'dont',
                'key'      => 'trigger2',
                'type'     => 'boolean',
                'value'    => 'false',
            ]]
        ];

        $resp = Http::withHeaders([
            'X-Shopify-Access-Token' => $shop->access_token,
            'Content-Type'           => 'application/json',
        ])->post($endpoint, ['query' => $mutation, 'variables' => $variables]);

        $body = $resp->json();
        if (!$resp->successful() || !empty($body['errors']) || !empty($body['data']['metafieldsSet']['userErrors'] ?? [])) {
            \Log::warning('setTrigger=false metafieldsSet issues', [
                'shop' => $shop->domain,
                'status' => $resp->status(),
                'body' => $body,
            ]);
        } else {
            \Log::info('setTrigger=false applied', ['shop' => $shop->domain, 'product' => $productGid]);
        }
    }

}
