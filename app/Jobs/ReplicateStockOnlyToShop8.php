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

class ReplicateStockOnlyToShop8 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = [10, 30, 60, 120];

    public function __construct(
        public int $targetShopId,
        public int $sourceShopId,
        public int $sourceProductId,
        public array $payload
    ) {}

    public function handle(): void
    {
        $target = Shop::findOrFail($this->targetShopId);
        if ((int)$target->id !== 8 && $target->domain !== 'eiluminat-bg.myshopify.com') {
            Log::warning('Stock-only job skipped: non-BG target', [
                'target_shop_id' => $target->id,
                'target_shop' => $target->domain,
                'source_shop_id' => $this->sourceShopId,
                'source_product_id' => $this->sourceProductId,
            ]);
            return;
        }

        $sourceBySku = $this->extractSourceVariantsBySku($this->payload);
        if (empty($sourceBySku)) {
            Log::info('Stock-only update no-op: no SKU variants in source payload', [
                'target_shop' => $target->domain,
                'source_pid' => $this->sourceProductId,
            ]);
            return;
        }

        $this->debug('Stock-only debug start', [
            'target_shop' => $target->domain,
            'source_shop_id' => $this->sourceShopId,
            'source_pid' => $this->sourceProductId,
            'source_skus' => array_map(
                fn ($sku, $v) => [
                    'sku' => $sku,
                    'payload_qty' => $v['inventory_quantity'] ?? null,
                    'payload_inventory_management' => $v['inventory_management'] ?? null,
                    'payload_inventory_management_present' => (bool)($v['inventory_management_present'] ?? false),
                ],
                array_keys($sourceBySku),
                array_values($sourceBySku),
            ),
        ]);

        $sourceTrackedBySku = [];
        $sourceInventoryBySku = [];
        $source = Shop::find($this->sourceShopId);
        if ($source) {
            try {
                $sourceTrackedBySku = $this->fetchSourceTrackedMapBySku(
                    $source,
                    'gid://shopify/Product/' . $this->sourceProductId
                );
            } catch (\Throwable $e) {
                Log::warning('Stock-only: source tracked map fetch failed, using payload fallback', [
                    'source_shop' => $source->domain,
                    'source_pid' => $this->sourceProductId,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($this->debugEnabled()) {
                try {
                    $sourceInventoryBySku = $this->fetchSourceInventorySnapshotBySku(
                        $source,
                        'gid://shopify/Product/' . $this->sourceProductId
                    );
                } catch (\Throwable $e) {
                    Log::warning('Stock-only debug: source inventory snapshot fetch failed', [
                        'source_shop' => $source->domain,
                        'source_pid' => $this->sourceProductId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $updatedTracked = 0;
        $updatedQuantity = 0;
        $skipped = 0;

        foreach ($sourceBySku as $sku => $src) {
            try {
                $targetVariant = $this->fetchTargetVariantBySku($target, $sku);
            } catch (\Throwable $e) {
                $skipped++;
                Log::warning('Stock-only: target variant lookup failed', [
                    'target_shop' => $target->domain,
                    'source_pid' => $this->sourceProductId,
                    'sku' => $sku,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (!$targetVariant) {
                $skipped++;
                Log::info('Stock-only: target variant not found by SKU', [
                    'target_shop' => $target->domain,
                    'source_pid' => $this->sourceProductId,
                    'sku' => $sku,
                ]);
                continue;
            }

            $variantGid = $targetVariant['variant_gid'] ?? null;
            $inventoryItemId = $targetVariant['inventory_item_id'] ?? null;
            $locationIds = $targetVariant['location_ids'] ?? [];

            if (!$variantGid || !$inventoryItemId) {
                $skipped++;
                Log::warning('Stock-only: incomplete target variant inventory data', [
                    'target_shop' => $target->domain,
                    'sku' => $sku,
                    'variant_gid' => $variantGid,
                    'inventory_item_id' => $inventoryItemId,
                ]);
                continue;
            }

            $trackedWanted = $this->resolveTrackedWanted($sku, $src, $sourceTrackedBySku);
            $this->debug('Stock-only debug SKU resolved', [
                'sku' => $sku,
                'source_payload_qty' => $src['inventory_quantity'] ?? null,
                'source_payload_inventory_management' => $src['inventory_management'] ?? null,
                'source_tracked_graphql' => $sourceTrackedBySku[$sku] ?? null,
                'source_inventory_graphql' => $sourceInventoryBySku[$sku] ?? null,
                'target_variant_gid' => $variantGid,
                'target_inventory_item_id' => $inventoryItemId,
                'target_locations' => $locationIds,
                'tracked_wanted' => $trackedWanted,
            ]);

            $beforeSnapshot = null;
            if ($this->debugEnabled()) {
                try {
                    $beforeSnapshot = $this->fetchVariantInventorySnapshot($target, $variantGid);
                } catch (\Throwable $e) {
                    Log::warning('Stock-only debug: target before snapshot failed', [
                        'target_shop' => $target->domain,
                        'sku' => $sku,
                        'variant_gid' => $variantGid,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            $this->debug('Stock-only debug target BEFORE', [
                'sku' => $sku,
                'variant_gid' => $variantGid,
                'snapshot' => $beforeSnapshot,
            ]);

            if ($trackedWanted !== null) {
                try {
                    $this->inventoryItemUpdate($target, $inventoryItemId, $trackedWanted);
                    $updatedTracked++;
                } catch (\Throwable $e) {
                    $skipped++;
                    Log::warning('Stock-only: inventory tracked update failed', [
                        'target_shop' => $target->domain,
                        'sku' => $sku,
                        'variant_gid' => $variantGid,
                        'tracked' => $trackedWanted,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            if ($trackedWanted === false) {
                $skipped++;
                Log::info('Stock-only: skip quantity update because tracking is off', [
                    'target_shop' => $target->domain,
                    'sku' => $sku,
                ]);
                continue;
            }

            if (!array_key_exists('inventory_quantity', $src) || $src['inventory_quantity'] === null) {
                $skipped++;
                continue;
            }

            try {
                $this->inventorySetQuantities(
                    shop: $target,
                    inventoryItemId: $inventoryItemId,
                    locationIds: $locationIds,
                    qty: (int)$src['inventory_quantity'],
                );
                $updatedQuantity++;
            } catch (\Throwable $e) {
                $skipped++;
                Log::warning('Stock-only: inventory quantity update failed', [
                    'target_shop' => $target->domain,
                    'sku' => $sku,
                    'variant_gid' => $variantGid,
                    'qty' => (int)$src['inventory_quantity'],
                    'error' => $e->getMessage(),
                ]);
            }

            if ($this->debugEnabled()) {
                try {
                    $afterSnapshot = $this->fetchVariantInventorySnapshot($target, $variantGid);
                    $this->debug('Stock-only debug target AFTER', [
                        'sku' => $sku,
                        'variant_gid' => $variantGid,
                        'snapshot' => $afterSnapshot,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Stock-only debug: target after snapshot failed', [
                        'target_shop' => $target->domain,
                        'sku' => $sku,
                        'variant_gid' => $variantGid,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('Stock-only update finished for BG store', [
            'target_shop' => $target->domain,
            'source_pid' => $this->sourceProductId,
            'source_variants_with_sku' => count($sourceBySku),
            'tracked_updates' => $updatedTracked,
            'quantity_updates' => $updatedQuantity,
            'skipped' => $skipped,
        ]);
    }

    private function extractSourceVariantsBySku(array $payload): array
    {
        $variants = $payload['variants'] ?? [];
        if (!is_array($variants)) {
            return [];
        }

        $out = [];
        foreach ($variants as $variant) {
            $sku = trim((string)($variant['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $imPresent = array_key_exists('inventory_management', $variant);
            $out[$sku] = [
                'inventory_management_present' => $imPresent,
                'inventory_management' => $imPresent ? ($variant['inventory_management'] ?? null) : null,
                'inventory_quantity' => $variant['inventory_quantity'] ?? null,
            ];
        }

        return $out;
    }

    private function fetchSourceTrackedMapBySku(Shop $shop, string $productGid): array
    {
        $q = <<<'GQL'
        query($id: ID!) {
          product(id: $id) {
            variants(first: 250) {
              nodes {
                sku
                inventoryItem { tracked }
              }
            }
          }
        }
        GQL;

        $r = $this->gql($shop, $q, ['id' => $productGid]);
        $nodes = $r['data']['product']['variants']['nodes'] ?? [];

        $map = [];
        foreach ($nodes as $node) {
            $sku = trim((string)($node['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $map[$sku] = (bool)($node['inventoryItem']['tracked'] ?? false);
        }

        return $map;
    }

    private function fetchSourceInventorySnapshotBySku(Shop $shop, string $productGid): array
    {
        $q = <<<'GQL'
        query($id: ID!) {
          product(id: $id) {
            variants(first: 250) {
              nodes {
                id
                sku
                inventoryItem {
                  id
                  tracked
                  inventoryLevels(first: 50) {
                    nodes {
                      location { id name }
                      quantities(names: ["available"]) { name quantity }
                    }
                  }
                }
              }
            }
          }
        }
        GQL;

        $r = $this->gql($shop, $q, ['id' => $productGid]);
        if (!empty($r['errors'])) {
            throw new \RuntimeException('source inventory snapshot query errors: ' . json_encode($r['errors']));
        }

        $nodes = $r['data']['product']['variants']['nodes'] ?? [];
        $map = [];
        foreach ($nodes as $node) {
            $sku = trim((string)($node['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $levels = $node['inventoryItem']['inventoryLevels']['nodes'] ?? [];
            $map[$sku] = [
                'variant_gid' => $node['id'] ?? null,
                'inventory_item_id' => $node['inventoryItem']['id'] ?? null,
                'tracked' => (bool)($node['inventoryItem']['tracked'] ?? false),
                'levels' => $this->normalizeLevelSnapshot($levels),
            ];
        }

        return $map;
    }

    private function fetchTargetVariantBySku(Shop $shop, string $sku): ?array
    {
        $q = <<<'GQL'
        query($query: String!) {
          productVariants(first: 10, query: $query) {
            nodes {
              id
              sku
              product { id handle title }
              inventoryItem {
                id
                tracked
                inventoryLevels(first: 50) {
                  nodes {
                    location { id name }
                    quantities(names: ["available"]) { name quantity }
                  }
                }
              }
            }
          }
        }
        GQL;

        $query = 'sku:"' . addslashes($sku) . '"';
        $r = $this->gql($shop, $q, ['query' => $query]);
        if (!empty($r['errors'])) {
            $this->debug('Stock-only debug: extended SKU lookup failed; fallback to minimal query', [
                'target_shop' => $shop->domain,
                'sku' => $sku,
                'errors' => $r['errors'],
            ]);

            $fallback = <<<'GQL'
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

            $r = $this->gql($shop, $fallback, ['query' => $query]);
            if (!empty($r['errors'])) {
                throw new \RuntimeException('target SKU lookup query errors: ' . json_encode($r['errors']));
            }
        }

        $nodes = $r['data']['productVariants']['nodes'] ?? [];
        if (empty($nodes)) {
            return null;
        }

        $exact = array_values(array_filter($nodes, function ($n) use ($sku) {
            return strcasecmp((string)($n['sku'] ?? ''), $sku) === 0;
        }));

        if (count($exact) > 1) {
            Log::warning('Stock-only: ambiguous target variants by SKU (exact matches)', [
                'target_shop' => $shop->domain,
                'sku' => $sku,
                'matches' => count($exact),
            ]);
            return null;
        }

        if (count($exact) === 1) {
            $node = $exact[0];
        } elseif (count($nodes) === 1) {
            $node = $nodes[0];
        } else {
            Log::warning('Stock-only: ambiguous target variants by SKU', [
                'target_shop' => $shop->domain,
                'sku' => $sku,
                'matches' => count($nodes),
            ]);
            return null;
        }

        $locationIds = [];
        foreach (($node['inventoryItem']['inventoryLevels']['nodes'] ?? []) as $level) {
            $locId = $level['location']['id'] ?? null;
            if ($locId) {
                $locationIds[] = $locId;
            }
        }

        return [
            'variant_gid' => $node['id'] ?? null,
            'sku' => $node['sku'] ?? null,
            'product' => [
                'id' => $node['product']['id'] ?? null,
                'handle' => $node['product']['handle'] ?? null,
                'title' => $node['product']['title'] ?? null,
            ],
            'inventory_item_id' => $node['inventoryItem']['id'] ?? null,
            'tracked' => (bool)($node['inventoryItem']['tracked'] ?? false),
            'location_ids' => $locationIds,
            'levels' => $this->normalizeLevelSnapshot($node['inventoryItem']['inventoryLevels']['nodes'] ?? []),
        ];
    }

    private function fetchVariantInventorySnapshot(Shop $shop, string $variantGid): array
    {
        $q = <<<'GQL'
        query($id: ID!) {
          productVariant(id: $id) {
            id
            sku
            product { id handle title }
            inventoryItem {
              id
              tracked
              inventoryLevels(first: 50) {
                nodes {
                  location { id name }
                  quantities(names: ["available"]) { name quantity }
                }
              }
            }
          }
        }
        GQL;

        $r = $this->gql($shop, $q, ['id' => $variantGid]);
        if (!empty($r['errors'])) {
            throw new \RuntimeException('variant snapshot query errors: ' . json_encode($r['errors']));
        }

        $node = $r['data']['productVariant'] ?? null;
        if (!$node) {
            return [];
        }

        return [
            'variant_gid' => $node['id'] ?? null,
            'sku' => $node['sku'] ?? null,
            'product' => [
                'id' => $node['product']['id'] ?? null,
                'handle' => $node['product']['handle'] ?? null,
                'title' => $node['product']['title'] ?? null,
            ],
            'inventory_item_id' => $node['inventoryItem']['id'] ?? null,
            'tracked' => (bool)($node['inventoryItem']['tracked'] ?? false),
            'levels' => $this->normalizeLevelSnapshot($node['inventoryItem']['inventoryLevels']['nodes'] ?? []),
        ];
    }

    private function normalizeLevelSnapshot(array $levels): array
    {
        $out = [];
        foreach ($levels as $level) {
            $locationId = $level['location']['id'] ?? null;
            $locationName = $level['location']['name'] ?? null;
            $available = null;

            foreach (($level['quantities'] ?? []) as $q) {
                if (($q['name'] ?? null) === 'available') {
                    $available = $q['quantity'] ?? null;
                    break;
                }
            }

            $out[] = [
                'location_id' => $locationId,
                'location_name' => $locationName,
                'available' => $available,
            ];
        }

        return $out;
    }

    private function resolveTrackedWanted(string $sku, array $srcVariant, array $sourceTrackedBySku): ?bool
    {
        if (array_key_exists($sku, $sourceTrackedBySku)) {
            return (bool)$sourceTrackedBySku[$sku];
        }

        $present = (bool)($srcVariant['inventory_management_present'] ?? false);
        if (!$present) {
            return null;
        }

        $val = strtolower((string)($srcVariant['inventory_management'] ?? ''));
        return $val === 'shopify';
    }

    private function inventoryItemUpdate(Shop $shop, string $inventoryItemId, bool $tracked): void
    {
        $m = <<<'GQL'
        mutation inventoryItemUpdate($id: ID!, $tracked: Boolean!) {
          inventoryItemUpdate(id: $id, input: { tracked: $tracked }) {
            inventoryItem { id tracked }
            userErrors { field message }
          }
        }
        GQL;

        $res = $this->gql($shop, $m, [
            'id' => $inventoryItemId,
            'tracked' => $tracked,
        ]);

        if (!empty($res['errors'])) {
            throw new \RuntimeException('inventoryItemUpdate errors: ' . json_encode($res['errors']));
        }

        $ue = $res['data']['inventoryItemUpdate']['userErrors'] ?? [];
        if (!empty($ue)) {
            throw new \RuntimeException('inventoryItemUpdate userErrors: ' . json_encode($ue));
        }
    }

    private function inventorySetQuantities(Shop $shop, string $inventoryItemId, array $locationIds, int $qty): void
    {
        if (!$inventoryItemId || empty($locationIds)) {
            return;
        }

        $quantities = array_map(fn($locId) => [
            'inventoryItemId' => $inventoryItemId,
            'locationId' => $locId,
            'quantity' => $qty,
        ], $locationIds);

        $m = <<<'GQL'
        mutation inventorySetQuantities($input: InventorySetQuantitiesInput!) {
          inventorySetQuantities(input: $input) {
            inventoryAdjustmentGroup { id }
            userErrors { field message code }
          }
        }
        GQL;

        $res = $this->gql($shop, $m, ['input' => [
            'reason' => 'correction',
            'name' => 'available',
            'ignoreCompareQuantity' => true,
            'quantities' => $quantities,
        ]]);

        if (!empty($res['errors'])) {
            throw new \RuntimeException('inventorySetQuantities errors: ' . json_encode($res['errors']));
        }

        $ue = $res['data']['inventorySetQuantities']['userErrors'] ?? [];
        if (!empty($ue)) {
            throw new \RuntimeException('inventorySetQuantities userErrors: ' . json_encode($ue));
        }
    }

    private function gql(Shop $shop, string $query, array $variables = []): array
    {
        $version = $shop->api_version ?: '2025-01';
        $endpoint = "https://{$shop->domain}/admin/api/{$version}/graphql.json";

        $client = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);

        $response = $client->post($endpoint, [
            'headers' => [
                'X-Shopify-Access-Token' => $shop->access_token,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'query' => $query,
                'variables' => $variables,
            ],
        ]);

        $decoded = json_decode((string)$response->getBody(), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid GraphQL JSON response');
        }

        return $decoded;
    }

    private function debugEnabled(): bool
    {
        return (bool)config('features.stock_only_bg.debug', false);
    }

    private function debug(string $message, array $context = []): void
    {
        if (!$this->debugEnabled()) {
            return;
        }

        Log::info('[BG-STOCK-DEBUG] ' . $message, $context);
    }
}
