<?php

namespace Tests\Unit;

use App\Models\Shop;
use App\Services\Shopify\ShopifyParentIdentityResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyParentIdentityResolverRepairTest extends TestCase
{
    public function test_it_repairs_an_empty_parentproduct_on_an_explicit_product_gid(): void
    {
        $shop = $this->shop();
        $fetchCount = 0;

        Http::fake(function ($request) use (&$fetchCount) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'ParentIdentityProductById')) {
                $fetchCount++;

                return Http::response([
                    'data' => ['product' => [
                        'id' => 'gid://shopify/Product/200',
                        'legacyResourceId' => '200',
                        'title' => 'Legacy backup product',
                        'handle' => 'legacy-backup-product',
                        'metafield' => $fetchCount === 1 ? null : ['value' => '100'],
                    ]],
                ]);
            }

            if (str_contains($query, 'ParentIdentityProductSearch')) {
                return Http::response(['data' => ['products' => [
                    'nodes' => [],
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                ]]]);
            }

            if (str_contains($query, 'RepairMissingParentProduct')) {
                return Http::response(['data' => ['metafieldsSet' => ['userErrors' => []]]]);
            }

            return Http::response(['errors' => [['message' => 'Unexpected request']]], 500);
        });

        $repaired = app(ShopifyParentIdentityResolver::class)
            ->repairMissingParentProduct($shop, 100, 'gid://shopify/Product/200');

        $this->assertTrue($repaired);
        Http::assertSent(fn ($request) =>
            str_contains((string) ($request->data()['query'] ?? ''), 'RepairMissingParentProduct')
            && ($request->data()['variables']['metafields'][0]['value'] ?? null) === '100'
        );
    }

    public function test_it_does_not_overwrite_a_conflicting_parentproduct(): void
    {
        $shop = $this->shop();

        Http::fake([
            '*' => Http::response(['data' => ['product' => [
                'id' => 'gid://shopify/Product/200',
                'legacyResourceId' => '200',
                'title' => 'Legacy backup product',
                'handle' => 'legacy-backup-product',
                'metafield' => ['value' => '999'],
            ]]]),
        ]);

        $repaired = app(ShopifyParentIdentityResolver::class)
            ->repairMissingParentProduct($shop, 100, 'gid://shopify/Product/200');

        $this->assertFalse($repaired);
        Http::assertSentCount(1);
    }

    public function test_it_accepts_the_metafield_returned_by_the_mutation_before_search_indexing_catches_up(): void
    {
        $shop = $this->shop();

        Http::fake(function ($request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'ParentIdentityProductById')) {
                return Http::response(['data' => ['product' => [
                    'id' => 'gid://shopify/Product/200',
                    'legacyResourceId' => '200',
                    'title' => 'Legacy backup product',
                    'handle' => 'legacy-backup-product',
                    'metafield' => null,
                ]]]);
            }

            if (str_contains($query, 'ParentIdentityProductSearch')) {
                return Http::response(['data' => ['products' => [
                    'nodes' => [],
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                ]]]);
            }

            if (str_contains($query, 'RepairMissingParentProduct')) {
                return Http::response(['data' => ['metafieldsSet' => [
                    'metafields' => [['key' => 'parentproduct', 'value' => '100']],
                    'userErrors' => [],
                ]]]);
            }

            return Http::response(['errors' => [['message' => 'Unexpected request']]], 500);
        });

        $repaired = app(ShopifyParentIdentityResolver::class)
            ->repairMissingParentProduct($shop, 100, 'gid://shopify/Product/200');

        $this->assertTrue($repaired);
    }

    private function shop(): Shop
    {
        return new Shop([
            'domain' => 'eiluminatbackup.myshopify.com',
            'access_token' => 'test-token',
            'api_version' => '2025-01',
        ]);
    }
}
