<?php

namespace App\Services\Shopify;

use App\Models\ProductMirror;
use App\Models\ProductParentBackfillCandidate;
use App\Models\Shop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductParentBackfillService
{
    private const DEFAULT_API_VERSION = '2025-01';

    public function scan(
        Shop $sourceShop,
        ?Shop $onlyTargetShop = null,
        ?int $limit = null,
        bool $apply = false,
        ?callable $progress = null
    ): array
    {
        $sourceProducts = $this->fetchProducts($sourceShop, $limit);
        $progress && $progress('source_scanned', [
            'shop' => $sourceShop->domain,
            'count' => count($sourceProducts),
        ]);

        $sourceIndex = $this->buildSourceIndex($sourceProducts);
        $targetShops = $onlyTargetShop
            ? collect([$onlyTargetShop])
            : $this->defaultTargetShops($sourceShop);

        $summary = [
            'source_shop' => $sourceShop->domain,
            'source_products' => count($sourceProducts),
            'targets' => [],
        ];

        foreach ($targetShops as $targetShop) {
            $targetProducts = $this->fetchProducts($targetShop, $limit);
            $progress && $progress('target_started', [
                'shop' => $targetShop->domain,
                'count' => count($targetProducts),
            ]);

            $mirrorsByTargetId = $this->loadMirrorsByTargetId($sourceShop, $targetShop, $targetProducts);
            $targetSummary = [
                'shop' => $targetShop->domain,
                'products' => count($targetProducts),
                'already_set' => 0,
                'matched' => 0,
                'unmatched' => 0,
                'ambiguous' => 0,
                'applied' => 0,
                'apply_errors' => 0,
            ];

            foreach ($targetProducts as $targetProduct) {
                $match = $this->matchTargetProduct($targetProduct, $sourceIndex, $mirrorsByTargetId);
                $candidate = $this->persistCandidate($sourceShop, $targetShop, $targetProduct, $match);

                $targetSummary[$candidate->match_status] = ($targetSummary[$candidate->match_status] ?? 0) + 1;

                if ($apply && $this->shouldApplyParentProduct($candidate)) {
                    try {
                        $this->setParentProductMetafield($targetShop, $candidate);
                        $targetSummary['applied']++;
                    } catch (\Throwable $e) {
                        $targetSummary['apply_errors']++;
                        Log::error('Product parent backfill apply failed', [
                            'target_shop' => $targetShop->domain,
                            'target_product_id' => $candidate->target_product_id,
                            'source_product_id' => $candidate->source_product_id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $progress && $progress('target_product_processed', [
                    'shop' => $targetShop->domain,
                    'target_product_id' => $candidate->target_product_id,
                    'match_status' => $candidate->match_status,
                ]);
            }

            $progress && $progress('target_finished', [
                'shop' => $targetShop->domain,
                'summary' => $targetSummary,
            ]);

            $summary['targets'][] = $targetSummary;
        }

        return $summary;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchProducts(Shop $shop, ?int $limit): array
    {
        $query = <<<'GQL'
        query ProductParentBackfillProducts($cursor: String) {
          products(first: 100, after: $cursor) {
            pageInfo {
              hasNextPage
              endCursor
            }
            nodes {
              id
              legacyResourceId
              title
              handle
              status
              metafield(namespace: "custom", key: "parentproduct") {
                value
                type
              }
              images(first: 250) {
                nodes {
                  id
                }
              }
              variants(first: 100) {
                nodes {
                  id
                  legacyResourceId
                  sku
                }
              }
            }
          }
        }
        GQL;

        $products = [];
        $cursor = null;

        do {
            $body = $this->graphql($shop, $query, ['cursor' => $cursor]);
            $connection = $body['data']['products'] ?? [];
            $nodes = $connection['nodes'] ?? [];

            foreach ($nodes as $node) {
                $products[] = $this->normalizeProduct($node);

                if ($limit && count($products) >= $limit) {
                    return $products;
                }
            }

            $pageInfo = $connection['pageInfo'] ?? [];
            $cursor = $pageInfo['endCursor'] ?? null;
            $hasNextPage = (bool)($pageInfo['hasNextPage'] ?? false);
        } while ($hasNextPage);

        return $products;
    }

    private function normalizeProduct(array $node): array
    {
        $skus = [];
        foreach (($node['variants']['nodes'] ?? []) as $variant) {
            $sku = trim((string)($variant['sku'] ?? ''));
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }

        $parentproduct = $node['metafield']['value'] ?? null;

        return [
            'id' => (int)($node['legacyResourceId'] ?? 0),
            'gid' => (string)($node['id'] ?? ''),
            'title' => (string)($node['title'] ?? ''),
            'handle' => (string)($node['handle'] ?? ''),
            'status' => (string)($node['status'] ?? ''),
            'image_count' => count($node['images']['nodes'] ?? []),
            'skus' => array_values(array_unique($skus)),
            'parentproduct' => $this->normalizeParentProductValue($parentproduct),
        ];
    }

    private function buildSourceIndex(array $sourceProducts): array
    {
        $byId = [];
        $byHandle = [];
        $bySku = [];

        foreach ($sourceProducts as $sourceProduct) {
            $id = (int)$sourceProduct['id'];
            if ($id <= 0) {
                continue;
            }

            $byId[$id] = $sourceProduct;

            $handle = $this->canonical((string)$sourceProduct['handle']);
            if ($handle !== '') {
                $byHandle[$handle][] = $sourceProduct;
            }

            foreach ($sourceProduct['skus'] as $sku) {
                $skuKey = $this->canonical((string)$sku);
                if ($skuKey !== '') {
                    $bySku[$skuKey][] = $sourceProduct;
                }
            }
        }

        return [
            'by_id' => $byId,
            'by_handle' => $byHandle,
            'by_sku' => $bySku,
        ];
    }

    private function loadMirrorsByTargetId(Shop $sourceShop, Shop $targetShop, array $targetProducts): Collection
    {
        $targetIds = array_values(array_filter(array_map(
            fn(array $product) => (int)($product['id'] ?? 0),
            $targetProducts
        )));

        if (!$targetIds) {
            return collect();
        }

        return ProductMirror::query()
            ->where('source_shop_id', $sourceShop->id)
            ->where('target_shop_id', $targetShop->id)
            ->whereIn('target_product_id', $targetIds)
            ->get()
            ->keyBy('target_product_id');
    }

    private function matchTargetProduct(array $targetProduct, array $sourceIndex, Collection $mirrorsByTargetId): array
    {
        $parentproduct = $targetProduct['parentproduct'] ?? null;
        if ($parentproduct && isset($sourceIndex['by_id'][$parentproduct])) {
            return $this->matched(
                ProductParentBackfillCandidate::STATUS_ALREADY_SET,
                'parentproduct',
                $sourceIndex['by_id'][$parentproduct],
                ['parentproduct' => $parentproduct]
            );
        }

        if ($parentproduct && !isset($sourceIndex['by_id'][$parentproduct])) {
            return $this->unmatched('parentproduct_missing_source', [
                'parentproduct' => $parentproduct,
            ]);
        }

        $mirror = $mirrorsByTargetId->get((int)$targetProduct['id']);
        if ($mirror && $mirror->source_product_id && isset($sourceIndex['by_id'][(int)$mirror->source_product_id])) {
            return $this->matched(
                ProductParentBackfillCandidate::STATUS_MATCHED,
                'product_mirror',
                $sourceIndex['by_id'][(int)$mirror->source_product_id],
                ['product_mirror_id' => $mirror->id]
            );
        }

        $handle = $this->canonical((string)$targetProduct['handle']);
        if ($handle !== '') {
            $handleMatches = $sourceIndex['by_handle'][$handle] ?? [];
            if (count($handleMatches) === 1) {
                return $this->matched(
                    ProductParentBackfillCandidate::STATUS_MATCHED,
                    'handle',
                    $handleMatches[0],
                    ['handle' => $targetProduct['handle']]
                );
            }

            if (count($handleMatches) > 1) {
                return $this->ambiguous('handle', $handleMatches);
            }
        }

        $skuMatches = [];
        foreach ($targetProduct['skus'] as $sku) {
            foreach (($sourceIndex['by_sku'][$this->canonical((string)$sku)] ?? []) as $sourceProduct) {
                $skuMatches[(int)$sourceProduct['id']] = $sourceProduct;
            }
        }

        if (count($skuMatches) === 1) {
            $sourceProduct = array_values($skuMatches)[0];

            return $this->matched(
                ProductParentBackfillCandidate::STATUS_MATCHED,
                'sku',
                $sourceProduct,
                ['skus' => $targetProduct['skus']]
            );
        }

        if (count($skuMatches) > 1) {
            return $this->ambiguous('sku', array_values($skuMatches));
        }

        return $this->unmatched('no_match', [
            'handle' => $targetProduct['handle'],
            'skus' => $targetProduct['skus'],
        ]);
    }

    private function persistCandidate(Shop $sourceShop, Shop $targetShop, array $targetProduct, array $match): ProductParentBackfillCandidate
    {
        $sourceProduct = $match['source_product'] ?? null;

        return ProductParentBackfillCandidate::updateOrCreate(
            [
                'target_shop_id' => $targetShop->id,
                'target_product_id' => (int)$targetProduct['id'],
            ],
            [
                'source_shop_id' => $sourceProduct ? $sourceShop->id : null,
                'source_product_id' => $sourceProduct ? (int)$sourceProduct['id'] : null,
                'source_product_gid' => $sourceProduct['gid'] ?? null,
                'source_title' => $sourceProduct['title'] ?? null,
                'source_handle' => $sourceProduct['handle'] ?? null,
                'source_skus' => $sourceProduct['skus'] ?? null,
                'source_status' => $sourceProduct['status'] ?? null,
                'source_image_count' => $sourceProduct ? (int)$sourceProduct['image_count'] : 0,
                'target_product_gid' => $targetProduct['gid'],
                'target_title' => $targetProduct['title'],
                'target_handle' => $targetProduct['handle'],
                'target_skus' => $targetProduct['skus'],
                'target_status' => $targetProduct['status'],
                'target_image_count' => (int)$targetProduct['image_count'],
                'parentproduct_value' => $targetProduct['parentproduct'],
                'match_status' => $match['status'],
                'match_strategy' => $match['strategy'],
                'notes' => $match['notes'],
                'last_scanned_at' => now(),
            ]
        );
    }

    private function shouldApplyParentProduct(ProductParentBackfillCandidate $candidate): bool
    {
        if (!$candidate->source_product_id || !$candidate->target_product_gid) {
            return false;
        }

        if (!in_array($candidate->match_status, [
            ProductParentBackfillCandidate::STATUS_MATCHED,
            ProductParentBackfillCandidate::STATUS_ALREADY_SET,
        ], true)) {
            return false;
        }

        return (int)$candidate->parentproduct_value !== (int)$candidate->source_product_id;
    }

    private function setParentProductMetafield(Shop $targetShop, ProductParentBackfillCandidate $candidate): void
    {
        $mutation = <<<'GQL'
        mutation ProductParentBackfillSet($metafields: [MetafieldsSetInput!]!) {
          metafieldsSet(metafields: $metafields) {
            metafields {
              id
              namespace
              key
              value
            }
            userErrors {
              field
              message
              code
            }
          }
        }
        GQL;

        $body = $this->graphql($targetShop, $mutation, [
            'metafields' => [[
                'ownerId' => $candidate->target_product_gid,
                'namespace' => 'custom',
                'key' => 'parentproduct',
                'type' => 'number_integer',
                'value' => (string)$candidate->source_product_id,
            ]],
        ]);

        $errors = $body['data']['metafieldsSet']['userErrors'] ?? [];
        if ($errors) {
            throw new \RuntimeException('Shopify metafieldsSet failed: '.json_encode($errors));
        }

        $candidate->forceFill([
            'parentproduct_value' => (int)$candidate->source_product_id,
            'match_status' => ProductParentBackfillCandidate::STATUS_ALREADY_SET,
            'match_strategy' => $candidate->match_strategy ?: 'applied',
        ])->save();
    }

    private function graphql(Shop $shop, string $query, array $variables = []): array
    {
        $version = $shop->api_version ?: self::DEFAULT_API_VERSION;
        $endpoint = "https://{$shop->domain}/admin/api/{$version}/graphql.json";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $shop->access_token,
            'Content-Type' => 'application/json',
        ])->post($endpoint, [
            'query' => $query,
            'variables' => $variables,
        ]);

        $body = $response->json() ?: [];
        if ($response->failed() || !empty($body['errors'])) {
            Log::error('Product parent backfill Shopify GraphQL failed', [
                'shop' => $shop->domain,
                'status' => $response->status(),
                'errors' => $body['errors'] ?? null,
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('Product parent backfill Shopify GraphQL failed for '.$shop->domain);
        }

        return $body;
    }

    private function defaultTargetShops(Shop $sourceShop): Collection
    {
        $backupDomain = (string)config('features.bem_watermark_sync.backup_shop_domain');

        return Shop::query()
            ->where('is_active', true)
            ->where('id', '!=', $sourceShop->id)
            ->when($backupDomain !== '', fn($query) => $query->where('domain', '!=', $backupDomain))
            ->orderBy('id')
            ->get();
    }

    private function matched(string $status, string $strategy, array $sourceProduct, array $notes): array
    {
        return [
            'status' => $status,
            'strategy' => $strategy,
            'source_product' => $sourceProduct,
            'notes' => $notes,
        ];
    }

    private function unmatched(string $strategy, array $notes): array
    {
        return [
            'status' => ProductParentBackfillCandidate::STATUS_UNMATCHED,
            'strategy' => $strategy,
            'source_product' => null,
            'notes' => $notes,
        ];
    }

    private function ambiguous(string $strategy, array $matches): array
    {
        return [
            'status' => ProductParentBackfillCandidate::STATUS_AMBIGUOUS,
            'strategy' => $strategy,
            'source_product' => null,
            'notes' => [
                'candidate_source_product_ids' => array_values(array_map(
                    fn(array $product) => (int)$product['id'],
                    $matches
                )),
            ],
        ];
    }

    private function normalizeParentProductValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ctype_digit((string)$value) ? (int)$value : null;
    }

    private function canonical(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
