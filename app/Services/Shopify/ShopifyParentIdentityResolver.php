<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;

final class ShopifyParentIdentityResolver
{
    /**
     * @return array{status: 'found'|'missing'|'ambiguous', product: ?array, candidates: array<int, array>}
     */
    public function resolveProduct(
        Shop $shop,
        int $sourceProductId,
        ?string $expectedTargetGid = null
    ): array {
        $validatedExpected = null;
        if ($expectedTargetGid) {
            $expected = $this->fetchProduct($shop, $expectedTargetGid);

            if ($this->parentValue($expected, 'parentproduct') === (string) $sourceProductId) {
                $validatedExpected = $expected;
            }
        }

        $matches = array_values(array_filter(
            $this->searchProductsByParentProduct($shop, $sourceProductId),
            fn (array $product): bool =>
                $this->parentValue($product, 'parentproduct') === (string) $sourceProductId
        ));

        $matchesByGid = [];
        foreach ($matches as $match) {
            if (!empty($match['id'])) {
                $matchesByGid[$match['id']] = $match;
            }
        }
        if ($validatedExpected && !empty($validatedExpected['id'])) {
            $matchesByGid[$validatedExpected['id']] = $validatedExpected;
        }
        $matches = array_values($matchesByGid);

        if (count($matches) === 1) {
            return [
                'status' => 'found',
                'product' => $matches[0],
                'candidates' => $matches,
            ];
        }

        return [
            'status' => count($matches) > 1 ? 'ambiguous' : 'missing',
            'product' => null,
            'candidates' => $matches,
        ];
    }

    /**
     * @return array{
     *     nodes_by_gid: array<string, array>,
     *     by_parent_id: array<string, array>,
     *     ambiguous_parent_ids: array<string, array<int, array>>,
     *     unmanaged_gids: array<int, string>
     * }
     */
    public function targetVariantState(Shop $shop, string $productGid): array
    {
        $nodes = [];
        $after = null;

        do {
            $response = $this->request($shop, <<<'GQL'
                query ParentIdentityVariants($id: ID!, $after: String) {
                  product(id: $id) {
                    variants(first: 100, after: $after) {
                      nodes {
                        id
                        legacyResourceId
                        metafield(namespace: "custom", key: "parentvariant") {
                          value
                        }
                      }
                      pageInfo {
                        hasNextPage
                        endCursor
                      }
                    }
                  }
                }
                GQL, [
                'id' => $productGid,
                'after' => $after,
            ]);

            $product = $response['data']['product'] ?? null;
            if (!$product) {
                throw new \RuntimeException('Target product not found while resolving parentvariant mappings: '.$productGid);
            }

            $connection = $product['variants'] ?? [];
            array_push($nodes, ...($connection['nodes'] ?? []));
            $hasNextPage = (bool) ($connection['pageInfo']['hasNextPage'] ?? false);
            $after = $connection['pageInfo']['endCursor'] ?? null;
        } while ($hasNextPage && $after);

        $nodesByGid = [];
        $groupedByParentId = [];
        $unmanagedGids = [];

        foreach ($nodes as $node) {
            $gid = (string) ($node['id'] ?? '');
            if ($gid === '') {
                continue;
            }

            $nodesByGid[$gid] = $node;
            $parentId = $this->parentValue($node, 'parentvariant');

            if ($parentId === null || !ctype_digit($parentId) || (int) $parentId <= 0) {
                $unmanagedGids[] = $gid;
                continue;
            }

            $groupedByParentId[$parentId][] = $node;
        }

        $byParentId = [];
        $ambiguousParentIds = [];

        foreach ($groupedByParentId as $parentId => $matches) {
            if (count($matches) === 1) {
                $byParentId[$parentId] = $matches[0];
            } else {
                $ambiguousParentIds[$parentId] = array_values($matches);
            }
        }

        return [
            'nodes_by_gid' => $nodesByGid,
            'by_parent_id' => $byParentId,
            'ambiguous_parent_ids' => $ambiguousParentIds,
            'unmanaged_gids' => array_values($unmanagedGids),
        ];
    }

    public function repairMissingParentProduct(
        Shop $shop,
        int $sourceProductId,
        string $expectedTargetGid
    ): bool {
        $expected = $this->fetchProduct($shop, $expectedTargetGid);
        if (!$expected || empty($expected['id'])) {
            return false;
        }

        // Never overwrite an existing identity, even when it points elsewhere.
        if ($this->parentValue($expected, 'parentproduct') !== null) {
            return false;
        }

        // Do not claim the cached product if another live product already owns
        // this source ID. The normal resolver must surface that state instead.
        if (!empty($this->searchProductsByParentProduct($shop, $sourceProductId))) {
            return false;
        }

        $response = $this->request($shop, <<<'GQL'
            mutation RepairMissingParentProduct($metafields: [MetafieldsSetInput!]!) {
              metafieldsSet(metafields: $metafields) {
                metafields { key value }
                userErrors { field message code }
              }
            }
            GQL, [
                'metafields' => [[
                    'ownerId' => $expectedTargetGid,
                    'namespace' => 'custom',
                    'key' => 'parentproduct',
                    'type' => 'number_integer',
                    'value' => (string) $sourceProductId,
                ]],
            ]);

        if (!empty($response['data']['metafieldsSet']['userErrors'] ?? [])) {
            return false;
        }

        $written = $response['data']['metafieldsSet']['metafields'][0] ?? null;
        if (
            ($written['key'] ?? null) === 'parentproduct'
            && ($written['value'] ?? null) === (string) $sourceProductId
        ) {
            return true;
        }

        $verified = $this->resolveProduct($shop, $sourceProductId, $expectedTargetGid);

        return $verified['status'] === 'found'
            && ($verified['product']['id'] ?? null) === $expectedTargetGid;
    }

    private function fetchProduct(Shop $shop, string $productGid): ?array
    {
        $response = $this->request($shop, <<<'GQL'
            query ParentIdentityProductById($id: ID!) {
              product(id: $id) {
                id
                legacyResourceId
                title
                handle
                metafield(namespace: "custom", key: "parentproduct") {
                  value
                }
              }
            }
            GQL, ['id' => $productGid]);

        return $response['data']['product'] ?? null;
    }

    /** @return array<int, array> */
    private function searchProductsByParentProduct(Shop $shop, int $sourceProductId): array
    {
        $nodes = [];
        $after = null;

        do {
            $response = $this->request($shop, <<<'GQL'
                query ParentIdentityProductSearch($query: String!, $after: String) {
                  products(first: 100, after: $after, query: $query) {
                    nodes {
                      id
                      legacyResourceId
                      title
                      handle
                      metafield(namespace: "custom", key: "parentproduct") {
                        value
                      }
                    }
                    pageInfo {
                      hasNextPage
                      endCursor
                    }
                  }
                }
                GQL, [
                'query' => 'metafields.custom.parentproduct:'.$this->quoteSearchValue((string) $sourceProductId),
                'after' => $after,
            ]);

            $connection = $response['data']['products'] ?? [];
            $pageNodes = $connection['nodes'] ?? [];
            foreach ($pageNodes as $node) {
                if ($this->parentValue($node, 'parentproduct') !== (string) $sourceProductId) {
                    throw new \RuntimeException(
                        'Shopify parentproduct search was not filtered for '.$shop->domain
                    );
                }
            }
            array_push($nodes, ...$pageNodes);
            $hasNextPage = (bool) ($connection['pageInfo']['hasNextPage'] ?? false);
            $after = $connection['pageInfo']['endCursor'] ?? null;
        } while ($hasNextPage && $after);

        return $nodes;
    }

    private function parentValue(?array $node, string $key): ?string
    {
        if (!$node) {
            return null;
        }

        $value = $node['metafield']['value'] ?? null;
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function quoteSearchValue(string $value): string
    {
        if (ctype_digit($value)) {
            return $value;
        }

        return '"'.addcslashes($value, "\\\"").'"';
    }

    private function request(Shop $shop, string $query, array $variables = []): array
    {
        $version = $shop->api_version ?: '2025-01';
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
            throw new \RuntimeException(
                'Shopify parent identity query failed for '.$shop->domain.': '.json_encode($body['errors'] ?? $body)
            );
        }

        return $body;
    }
}
