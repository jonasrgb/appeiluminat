<?php

namespace App\Jobs;

use App\Models\ProductMirror;
use App\Models\ProductParentBackfillCandidate;
use App\Models\Shop;
use App\Services\Shopify\BemWatermark\BemShopifyGraphqlClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReplicateProductDeleteToShop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = [10, 30, 60, 120];

    public function __construct(
        public int $targetShopId,
        public int $sourceShopId,
        public int $sourceProductId,
    ) {}

    public function handle(BemShopifyGraphqlClient $graphql): void
    {
        $target = Shop::findOrFail($this->targetShopId);
        $products = $this->targetProducts($graphql, $target);

        if ($products === []) {
            Log::info('Product delete replication skipped: no local target candidates', [
                'source_shop_id' => $this->sourceShopId,
                'source_product_id' => $this->sourceProductId,
                'target_shop' => $target->domain,
            ]);
            return;
        }

        foreach ($products as $product) {
            $targetProductGid = $product['gid'];
            $targetProductId = $product['id'];
            $fromMirror = (bool) ($product['from_mirror'] ?? false);
            $liveProduct = $this->fetchProduct($graphql, $target, $targetProductGid);

            if ($liveProduct === null) {
                $this->cleanupLocalState($targetProductId, $targetProductGid);
                Log::info('Product delete replication cleaned an already deleted target product', [
                    'source_product_id' => $this->sourceProductId,
                    'target_shop' => $target->domain,
                    'target_product_gid' => $targetProductGid,
                ]);
                continue;
            }

            $parentProduct = (string) ($liveProduct['metafield']['value'] ?? '');
            $isVerifiedByParent = $parentProduct === (string) $this->sourceProductId;
            $isLegacyMirrorWithoutParent = $parentProduct === '' && $fromMirror;

            if (!$isVerifiedByParent && !$isLegacyMirrorWithoutParent) {
                $this->cleanupLocalState($targetProductId, $targetProductGid);
                Log::warning('Product delete replication skipped target with mismatched parentproduct', [
                    'source_product_id' => $this->sourceProductId,
                    'target_shop' => $target->domain,
                    'target_product_gid' => $targetProductGid,
                    'live_parentproduct' => $parentProduct ?: null,
                ]);
                continue;
            }

            $this->deleteProduct($graphql, $target, $targetProductGid);
            $this->cleanupLocalState($targetProductId, $targetProductGid);

            Log::info('Product delete replication completed', [
                'source_product_id' => $this->sourceProductId,
                'target_shop' => $target->domain,
                'target_product_gid' => $targetProductGid,
                'strategy' => $isVerifiedByParent ? 'parentproduct' : 'legacy_product_mirror',
            ]);
        }
    }

    /** @return array<int, array{id: int|null, gid: string, from_mirror: bool}> */
    private function targetProducts(BemShopifyGraphqlClient $graphql, Shop $target): array
    {
        $products = [];

        ProductMirror::query()
            ->where('source_shop_id', $this->sourceShopId)
            ->where('source_product_id', $this->sourceProductId)
            ->where('target_shop_id', $this->targetShopId)
            ->get(['target_product_id', 'target_product_gid'])
            ->each(function (ProductMirror $mirror) use (&$products) {
                if (!$mirror->target_product_gid) {
                    return;
                }

                $products[$mirror->target_product_gid] = [
                    'id' => $mirror->target_product_id ? (int) $mirror->target_product_id : null,
                    'gid' => $mirror->target_product_gid,
                    'from_mirror' => true,
                ];
            });

        ProductParentBackfillCandidate::query()
            ->where('target_shop_id', $this->targetShopId)
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->where('source_shop_id', $this->sourceShopId)
                        ->where('source_product_id', $this->sourceProductId);
                })->orWhere('parentproduct_value', $this->sourceProductId);
            })
            ->get(['target_product_id', 'target_product_gid'])
            ->each(function (ProductParentBackfillCandidate $candidate) use (&$products) {
                if (!$candidate->target_product_gid) {
                    return;
                }

                $existing = $products[$candidate->target_product_gid] ?? null;
                $products[$candidate->target_product_gid] = [
                    'id' => (int) $candidate->target_product_id,
                    'gid' => $candidate->target_product_gid,
                    'from_mirror' => (bool) ($existing['from_mirror'] ?? false),
                ];
            });

        foreach ($this->findLiveProductsByParentProduct($graphql, $target) as $product) {
            $existing = $products[$product['gid']] ?? null;
            $product['from_mirror'] = (bool) ($existing['from_mirror'] ?? false);
            $products[$product['gid']] = $product;
        }

        return array_values($products);
    }

    /** @return array<int, array{id: int|null, gid: string, from_mirror: bool}> */
    private function findLiveProductsByParentProduct(BemShopifyGraphqlClient $graphql, Shop $target): array
    {
        $query = <<<'GQL'
        query ProductDeleteSearch($query: String!) {
          products(first: 250, query: $query) {
            nodes {
              id
              legacyResourceId
              metafield(namespace: "custom", key: "parentproduct") {
                value
              }
            }
          }
        }
        GQL;

        $response = $graphql->request($target, $query, [
            'query' => 'metafields.custom.parentproduct:'.$this->sourceProductId,
        ]);

        $products = [];
        foreach (($response['data']['products']['nodes'] ?? []) as $product) {
            if ((string) ($product['metafield']['value'] ?? '') !== (string) $this->sourceProductId) {
                continue;
            }

            if (empty($product['id'])) {
                continue;
            }

            $products[] = [
                'id' => isset($product['legacyResourceId']) ? (int) $product['legacyResourceId'] : null,
                'gid' => $product['id'],
                'from_mirror' => false,
            ];
        }

        return $products;
    }

    private function fetchProduct(BemShopifyGraphqlClient $graphql, Shop $target, string $productGid): ?array
    {
        $query = <<<'GQL'
        query ProductDeleteLookup($id: ID!) {
          product(id: $id) {
            id
            metafield(namespace: "custom", key: "parentproduct") {
              value
            }
          }
        }
        GQL;

        $response = $graphql->request($target, $query, ['id' => $productGid]);

        return $response['data']['product'] ?? null;
    }

    private function deleteProduct(BemShopifyGraphqlClient $graphql, Shop $target, string $productGid): void
    {
        $mutation = <<<'GQL'
        mutation ProductDelete($input: ProductDeleteInput!) {
          productDelete(input: $input) {
            deletedProductId
            userErrors {
              field
              message
            }
          }
        }
        GQL;

        $response = $graphql->request($target, $mutation, [
            'input' => ['id' => $productGid],
        ]);
        $result = $response['data']['productDelete'] ?? [];
        $errors = $result['userErrors'] ?? [];

        if (!empty($errors)) {
            throw new \RuntimeException('Shopify productDelete failed: '.json_encode($errors));
        }
    }

    private function cleanupLocalState(?int $targetProductId, string $targetProductGid): void
    {
        $mirrors = ProductMirror::query()
            ->where('source_shop_id', $this->sourceShopId)
            ->where('source_product_id', $this->sourceProductId)
            ->where('target_shop_id', $this->targetShopId)
            ->where(function ($query) use ($targetProductId, $targetProductGid) {
                $query->where('target_product_gid', $targetProductGid);

                if ($targetProductId !== null) {
                    $query->orWhere('target_product_id', $targetProductId);
                }
            })
            ->get();

        foreach ($mirrors as $mirror) {
            $mirror->delete();
        }

        $candidates = ProductParentBackfillCandidate::query()
            ->where('target_shop_id', $this->targetShopId)
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->where('source_shop_id', $this->sourceShopId)
                        ->where('source_product_id', $this->sourceProductId);
                })->orWhere('parentproduct_value', $this->sourceProductId);
            })
            ->where(function ($query) use ($targetProductId, $targetProductGid) {
                $query->where('target_product_gid', $targetProductGid);

                if ($targetProductId !== null) {
                    $query->orWhere('target_product_id', $targetProductId);
                }
            })
            ->get();

        foreach ($candidates as $candidate) {
            $candidate->delete();
        }
    }
}
