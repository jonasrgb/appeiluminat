<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Shopify\ShopifyParentIdentityResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyParentIdentityResolverTest extends TestCase
{
    public function test_it_accepts_a_cached_product_only_when_parentproduct_matches(): void
    {
        Http::fake(function (Request $request) {
            $query = $this->query($request);

            if (str_contains($query, 'ParentIdentityProductById')) {
                return Http::response([
                    'data' => [
                        'product' => $this->product(900, 700),
                    ],
                ]);
            }

            $this->assertStringContainsString('ParentIdentityProductSearch', $query);

            return Http::response([
                'data' => [
                    'products' => [
                        'nodes' => [$this->product(900, 700)],
                    ],
                ],
            ]);
        });

        $result = app(ShopifyParentIdentityResolver::class)->resolveProduct(
            $this->shop(),
            700,
            'gid://shopify/Product/900'
        );

        $this->assertSame('found', $result['status']);
        $this->assertSame('gid://shopify/Product/900', $result['product']['id']);
        Http::assertSentCount(2);
    }

    public function test_it_rejects_a_stale_cached_product_and_searches_only_by_parentproduct(): void
    {
        Http::fake(function (Request $request) {
            $query = $this->query($request);

            if (str_contains($query, 'ParentIdentityProductById')) {
                return Http::response([
                    'data' => [
                        'product' => $this->product(900, 111),
                    ],
                ]);
            }

            $this->assertStringContainsString('ParentIdentityProductSearch', $query);
            $this->assertSame(
                'metafields.custom.parentproduct:700',
                $request->data()['variables']['query'] ?? null
            );

            return Http::response([
                'data' => [
                    'products' => [
                        'nodes' => [$this->product(901, 700)],
                    ],
                ],
            ]);
        });

        $result = app(ShopifyParentIdentityResolver::class)->resolveProduct(
            $this->shop(),
            700,
            'gid://shopify/Product/900'
        );

        $this->assertSame('found', $result['status']);
        $this->assertSame('gid://shopify/Product/901', $result['product']['id']);

        Http::assertSent(function (Request $request): bool {
            $encoded = json_encode($request->data());

            return !str_contains($encoded, 'handle:') && !str_contains($encoded, 'sku:');
        });
        Http::assertSentCount(2);
    }

    public function test_it_reports_duplicate_parentproduct_values_as_ambiguous(): void
    {
        Http::fake(Http::response([
            'data' => [
                'products' => [
                    'nodes' => [
                        $this->product(901, 700),
                        $this->product(902, 700),
                    ],
                ],
            ],
        ]));

        $result = app(ShopifyParentIdentityResolver::class)->resolveProduct($this->shop(), 700);

        $this->assertSame('ambiguous', $result['status']);
        $this->assertNull($result['product']);
        $this->assertSame(
            ['gid://shopify/Product/901', 'gid://shopify/Product/902'],
            array_column($result['candidates'], 'id')
        );
    }

    public function test_cached_match_does_not_hide_a_duplicate_parentproduct(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($this->query($request), 'ParentIdentityProductById')) {
                return Http::response([
                    'data' => ['product' => $this->product(900, 700)],
                ]);
            }

            return Http::response([
                'data' => [
                    'products' => [
                        'nodes' => [
                            $this->product(900, 700),
                            $this->product(901, 700),
                        ],
                    ],
                ],
            ]);
        });

        $result = app(ShopifyParentIdentityResolver::class)->resolveProduct(
            $this->shop(),
            700,
            'gid://shopify/Product/900'
        );

        $this->assertSame('ambiguous', $result['status']);
        $this->assertNull($result['product']);
        $this->assertCount(2, $result['candidates']);
    }

    public function test_it_fails_closed_when_shopify_does_not_filter_parentproduct_search(): void
    {
        Http::fake(Http::response([
            'data' => [
                'products' => [
                    'nodes' => [
                        $this->product(901, 700),
                        $this->product(902, 999),
                    ],
                    'pageInfo' => [
                        'hasNextPage' => false,
                        'endCursor' => null,
                    ],
                ],
            ],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('parentproduct search was not filtered');

        app(ShopifyParentIdentityResolver::class)->resolveProduct($this->shop(), 700);
    }

    public function test_variant_state_indexes_only_unique_parentvariant_values(): void
    {
        Http::fake(Http::response([
            'data' => [
                'product' => [
                    'variants' => [
                        'nodes' => [
                            $this->variant(10, 501),
                            $this->variant(11, null),
                            $this->variant(12, 502),
                            $this->variant(13, 502),
                        ],
                        'pageInfo' => [
                            'hasNextPage' => false,
                            'endCursor' => null,
                        ],
                    ],
                ],
            ],
        ]));

        $state = app(ShopifyParentIdentityResolver::class)->targetVariantState(
            $this->shop(),
            'gid://shopify/Product/900'
        );

        $this->assertSame(
            'gid://shopify/ProductVariant/10',
            $state['by_parent_id']['501']['id']
        );
        $this->assertArrayNotHasKey('502', $state['by_parent_id']);
        $this->assertSame(
            ['gid://shopify/ProductVariant/12', 'gid://shopify/ProductVariant/13'],
            array_column($state['ambiguous_parent_ids']['502'], 'id')
        );
        $this->assertSame(['gid://shopify/ProductVariant/11'], $state['unmanaged_gids']);
    }

    private function shop(): Shop
    {
        return new Shop([
            'id' => 4,
            'name' => 'Lustreled',
            'domain' => 'lustreled.myshopify.com',
            'access_token' => 'test-token',
            'api_version' => '2025-01',
            'is_source' => false,
            'is_active' => true,
        ]);
    }

    private function product(int $id, int $parentProduct): array
    {
        return [
            'id' => 'gid://shopify/Product/'.$id,
            'legacyResourceId' => (string) $id,
            'title' => 'Product '.$id,
            'handle' => 'product-'.$id,
            'metafield' => ['value' => (string) $parentProduct],
        ];
    }

    private function variant(int $id, ?int $parentVariant): array
    {
        return [
            'id' => 'gid://shopify/ProductVariant/'.$id,
            'legacyResourceId' => (string) $id,
            'metafield' => $parentVariant === null
                ? null
                : ['value' => (string) $parentVariant],
        ];
    }

    private function query(Request $request): string
    {
        return (string) ($request->data()['query'] ?? '');
    }
}
