<?php

namespace App\Jobs;

use App\Models\Shop;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProcessStockWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = [10, 30, 60, 120];

    /** @var array<int, int> */
    private const TARGET_SHOP_IDS = [4, 5, 6, 8];
    private const ALERT_EMAIL = 'mitnickoff121@gmail.com';

    /**
     * @param  array{id:mixed,title:string,handle:string,sku:string|null}  $payload
     */
    public function __construct(
        public array $payload
    ) {}

    public function handle(): void
    {
        $sku = trim((string) ($this->payload['sku'] ?? ''));
        if ($sku === '') {
            Log::info('Stock queue job skipped: missing SKU', [
                'id' => (string) ($this->payload['id'] ?? ''),
                'title' => (string) ($this->payload['title'] ?? ''),
                'handle' => (string) ($this->payload['handle'] ?? ''),
                'job_id' => $this->job?->getJobId(),
            ]);
            return;
        }

        $shops = Shop::whereIn('id', self::TARGET_SHOP_IDS)->get()->keyBy('id');

        $updated = 0;
        $notFound = 0;
        $failed = 0;
        $errors = [];

        foreach (self::TARGET_SHOP_IDS as $shopId) {
            /** @var Shop|null $shop */
            $shop = $shops->get($shopId);

            if (!$shop) {
                $failed++;
                $errors[] = "Target shop id {$shopId} is missing from DB";
                continue;
            }

            try {
                $variant = $this->findVariantBySku($shop, $sku);
                if (!$variant) {
                    $notFound++;
                    continue;
                }

                $inventoryItemId = $variant['inventory_item_id'] ?? null;
                if (!$inventoryItemId) {
                    $notFound++;
                    continue;
                }

                $locationIds = $variant['location_ids'] ?? [];
                if (empty($locationIds) && $shop->locationGid()) {
                    $locationIds = [$shop->locationGid()];
                }
                if (empty($locationIds)) {
                    $notFound++;
                    continue;
                }

                $this->setInventoryZero($shop, $inventoryItemId, $locationIds);
                $updated++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Shop {$shop->id} ({$shop->domain}): {$e->getMessage()}";
            }
        }

        if ($failed > 0) {
            $this->sendFailureEmail(
                subject: 'Stock sync failed on one or more shops',
                body: "SKU: {$sku}\nUpdated: {$updated}\nNot found: {$notFound}\nFailed: {$failed}\nErrors:\n- " . implode("\n- ", $errors)
            );
        }
    }

    private function findVariantBySku(Shop $shop, string $sku): ?array
    {
        $query = <<<'GQL'
        query($query: String!) {
          productVariants(first: 10, query: $query) {
            nodes {
              id
              sku
              inventoryItem {
                id
                inventoryLevels(first: 50) {
                  nodes {
                    location { id }
                  }
                }
              }
            }
          }
        }
        GQL;

        $search = 'sku:"' . $this->escapeSearchValue($sku) . '"';
        $res = $this->gql($shop, $query, ['query' => $search]);

        if (!empty($res['errors'])) {
            throw new \RuntimeException('productVariants query errors: ' . json_encode($res['errors']));
        }

        $nodes = $res['data']['productVariants']['nodes'] ?? [];
        if (empty($nodes)) {
            return null;
        }

        $exact = array_values(array_filter($nodes, function (array $node) use ($sku) {
            return strcasecmp((string) ($node['sku'] ?? ''), $sku) === 0;
        }));

        if (count($exact) > 1) {
            return null;
        }

        if (count($exact) === 1) {
            $node = $exact[0];
        } elseif (count($nodes) === 1) {
            $node = $nodes[0];
        } else {
            return null;
        }

        $locationIds = [];
        foreach (($node['inventoryItem']['inventoryLevels']['nodes'] ?? []) as $level) {
            $locId = $level['location']['id'] ?? null;
            if ($locId) {
                $locationIds[] = (string) $locId;
            }
        }

        return [
            'variant_gid' => $node['id'] ?? null,
            'sku' => $node['sku'] ?? null,
            'inventory_item_id' => $node['inventoryItem']['id'] ?? null,
            'location_ids' => array_values(array_unique($locationIds)),
        ];
    }

    private function setInventoryZero(Shop $shop, string $inventoryItemId, array $locationIds): void
    {
        $locationIds = array_values(array_unique(array_filter($locationIds)));
        if (empty($locationIds)) {
            return;
        }

        $quantities = array_map(static fn (string $locationId) => [
            'inventoryItemId' => $inventoryItemId,
            'locationId' => $locationId,
            'quantity' => 0,
        ], $locationIds);

        $mutation = <<<'GQL'
        mutation inventorySetQuantities($input: InventorySetQuantitiesInput!) {
          inventorySetQuantities(input: $input) {
            inventoryAdjustmentGroup { id }
            userErrors { field message code }
          }
        }
        GQL;

        $res = $this->gql($shop, $mutation, [
            'input' => [
                'reason' => 'correction',
                'name' => 'available',
                'ignoreCompareQuantity' => true,
                'quantities' => $quantities,
            ],
        ]);

        if (!empty($res['errors'])) {
            throw new \RuntimeException('inventorySetQuantities errors: ' . json_encode($res['errors']));
        }

        $userErrors = $res['data']['inventorySetQuantities']['userErrors'] ?? [];
        if (!empty($userErrors)) {
            throw new \RuntimeException('inventorySetQuantities userErrors: ' . json_encode($userErrors));
        }
    }

    private function gql(Shop $shop, string $query, array $variables = []): array
    {
        $version = $shop->api_version ?: '2025-01';
        $endpoint = "https://{$shop->domain}/admin/api/{$version}/graphql.json";

        $client = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
        ]);

        $response = $client->post($endpoint, [
            'headers' => [
                'X-Shopify-Access-Token' => $shop->access_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'json' => [
                'query' => $query,
                'variables' => $variables,
            ],
        ]);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($status >= 400) {
            throw new \RuntimeException("Shopify GraphQL HTTP {$status}: {$body}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid Shopify GraphQL JSON response');
        }

        return $decoded;
    }

    private function escapeSearchValue(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    private function sendFailureEmail(string $subject, string $body): void
    {
        try {
            Mail::raw($body, function ($message) use ($subject) {
                $message->to(self::ALERT_EMAIL)->subject($subject);
            });
        } catch (\Throwable $mailException) {}
    }
}
