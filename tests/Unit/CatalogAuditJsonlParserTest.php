<?php

namespace Tests\Unit;

use App\Models\Shop;
use App\Services\Shopify\CatalogAuditJsonlParser;
use PHPUnit\Framework\TestCase;

class CatalogAuditJsonlParserTest extends TestCase
{
    public function test_it_reconstructs_order_independent_active_products_images_and_duplicate_skus(): void
    {
        $result = (new CatalogAuditJsonlParser)->parse($this->jsonl([
            [
                'id' => 'gid://shopify/ProductVariant/2001',
                'legacyResourceId' => '2001',
                'title' => 'Default',
                'sku' => 'sku-1',
                '__parentId' => 'gid://shopify/Product/200',
            ],
            [
                'id' => 'gid://shopify/ProductImage/900',
                '__parentId' => 'gid://shopify/Product/200',
            ],
            [
                'id' => 'gid://shopify/Product/100',
                'legacyResourceId' => '100',
                'title' => 'Lamp A',
                'handle' => 'lamp-a',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'gid://shopify/Product/200',
                'legacyResourceId' => '200',
                'title' => 'Lamp B',
                'handle' => 'lamp-b',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'gid://shopify/ProductVariant/1001',
                'legacyResourceId' => '1001',
                'title' => 'Red',
                'sku' => ' SKU-1 ',
                '__parentId' => 'gid://shopify/Product/100',
            ],
        ]), $this->shop());

        $this->assertSame(1, $result['missing_image_count']);
        $this->assertSame(1, $result['duplicate_sku_group_count']);
        $this->assertSame(2, $result['duplicate_sku_row_count']);

        $missing = $this->finding($result['findings'], 'missing_image');
        $this->assertSame('gid://shopify/Product/100', $missing['product_gid']);
        $this->assertSame(
            ['gid://shopify/Product/100'],
            array_column(
                array_filter(
                    $result['findings'],
                    static fn (array $finding): bool => $finding['finding_type'] === 'missing_image'
                ),
                'product_gid'
            )
        );
        $this->assertSame(
            'missing_image:da77e696c6249b798af5926232dc37fbcf476d9bce35c1d21c65a70d5c4cf1c2',
            $missing['fingerprint']
        );
        $this->assertSame('https://admin.shopify.com/store/example/products/100', $missing['shopify_admin_url']);

        $duplicates = array_values(array_filter(
            $result['findings'],
            static fn (array $finding): bool => $finding['finding_type'] === 'duplicate_sku'
        ));
        $this->assertCount(2, $duplicates);
        $this->assertSame([1001, 2001], array_column($duplicates, 'variant_legacy_id'));
        $this->assertSame(['sku-1', 'sku-1'], array_column($duplicates, 'normalized_sku'));
        $this->assertSame(
            [
                'duplicate_sku:9ce1382d6e4a773a4ee286513383a01ba1b841455952e4de988160f55515c8bf',
                'duplicate_sku:ace3a3dfec3d444d89e97a8d43129c0c01e564a03160d674296b50eaa7c0e586',
            ],
            array_column($duplicates, 'fingerprint')
        );
    }

    public function test_it_hashes_long_normalized_skus_into_bounded_deterministic_fingerprints(): void
    {
        $longSku = str_repeat('A', 255);
        $otherLongSku = str_repeat('B', 255);
        $rows = [
            [
                'id' => 'gid://shopify/Product/1000',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'gid://shopify/ProductVariant/1001',
                'sku' => " {$longSku} ",
                '__parentId' => 'gid://shopify/Product/1000',
            ],
            [
                'id' => 'gid://shopify/Product/1010',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'gid://shopify/ProductVariant/1011',
                'sku' => strtolower($longSku),
                '__parentId' => 'gid://shopify/Product/1010',
            ],
            [
                'id' => 'gid://shopify/Product/1020',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'gid://shopify/ProductVariant/1021',
                'sku' => " {$otherLongSku} ",
                '__parentId' => 'gid://shopify/Product/1020',
            ],
            [
                'id' => 'gid://shopify/Product/1030',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'gid://shopify/ProductVariant/1031',
                'sku' => strtolower($otherLongSku),
                '__parentId' => 'gid://shopify/Product/1030',
            ],
        ];

        $forward = (new CatalogAuditJsonlParser)->parse($this->jsonl($rows), $this->shop());
        $reverse = (new CatalogAuditJsonlParser)->parse($this->jsonl(array_reverse($rows)), $this->shop());
        $forwardDuplicates = $this->duplicateFindings($forward['findings']);
        $reverseDuplicates = $this->duplicateFindings($reverse['findings']);

        $this->assertSame($forwardDuplicates, $reverseDuplicates);
        $this->assertCount(4, $forwardDuplicates);
        $this->assertCount(
            4,
            array_filter(
                $forwardDuplicates,
                static fn (array $finding): bool => strlen($finding['fingerprint']) <= 255
            )
        );

        $longSkuFingerprints = array_column(
            array_filter(
                $forwardDuplicates,
                static fn (array $finding): bool => $finding['normalized_sku'] === strtolower($longSku)
            ),
            'fingerprint'
        );
        $otherLongSkuFingerprints = array_column(
            array_filter(
                $forwardDuplicates,
                static fn (array $finding): bool => $finding['normalized_sku'] === strtolower($otherLongSku)
            ),
            'fingerprint'
        );

        $this->assertCount(2, $longSkuFingerprints);
        $this->assertCount(2, $otherLongSkuFingerprints);
        $this->assertNotSame($longSkuFingerprints[0], $otherLongSkuFingerprints[0]);
    }

    public function test_it_bounds_fingerprints_for_absurdly_long_numeric_gids_and_preserves_identity(): void
    {
        $productGidA = 'gid://shopify/Product/'.str_repeat('1', 1000);
        $productGidB = 'gid://shopify/Product/'.str_repeat('2', 1000);
        $variantGidA = 'gid://shopify/ProductVariant/'.str_repeat('3', 1000);
        $variantGidB = 'gid://shopify/ProductVariant/'.str_repeat('4', 1000);

        $result = (new CatalogAuditJsonlParser)->parse($this->jsonl([
            [
                'id' => $productGidA,
                'status' => 'ACTIVE',
            ],
            [
                'id' => $productGidB,
                'status' => 'ACTIVE',
            ],
            [
                'id' => $variantGidA,
                'sku' => 'shared-sku',
                '__parentId' => $productGidA,
            ],
            [
                'id' => $variantGidB,
                'sku' => 'shared-sku',
                '__parentId' => $productGidB,
            ],
        ]), $this->shop());

        $missing = array_values(array_filter(
            $result['findings'],
            static fn (array $finding): bool => $finding['finding_type'] === 'missing_image'
        ));
        $duplicates = $this->duplicateFindings($result['findings']);

        $this->assertCount(2, $missing);
        $this->assertCount(2, $duplicates);
        foreach ($result['findings'] as $finding) {
            $this->assertLessThanOrEqual(255, strlen($finding['fingerprint']));
        }

        $this->assertNotSame($missing[0]['fingerprint'], $missing[1]['fingerprint']);
        $this->assertNotSame($duplicates[0]['fingerprint'], $duplicates[1]['fingerprint']);
        $this->assertSame(
            'missing_image:'.hash('sha256', $productGidA),
            $this->finding($result['findings'], 'missing_image')['fingerprint']
        );
        $this->assertSame(78, strlen($missing[0]['fingerprint']));
        $this->assertSame(78, strlen($duplicates[0]['fingerprint']));
    }

    public function test_it_ignores_draft_products_blank_skus_and_non_image_media(): void
    {
        $result = (new CatalogAuditJsonlParser)->parse($this->jsonl([
            [
                'id' => 'gid://shopify/Product/300',
                'legacyResourceId' => '300',
                'title' => 'Draft Lamp',
                'handle' => 'draft-lamp',
                'status' => 'DRAFT',
            ],
            [
                'id' => 'gid://shopify/ProductImage/301',
                '__parentId' => 'gid://shopify/Product/300',
            ],
            [
                'id' => 'gid://shopify/Product/400',
                'legacyResourceId' => '400',
                'title' => 'Video Lamp',
                'handle' => 'video-lamp',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'gid://shopify/ProductVideo/401',
                '__parentId' => 'gid://shopify/Product/400',
            ],
            [
                'id' => 'gid://shopify/ProductVariant/402',
                'legacyResourceId' => '402',
                'title' => 'Default',
                'sku' => '   ',
                '__parentId' => 'gid://shopify/Product/400',
            ],
        ]), $this->shop());

        $this->assertSame(1, $result['missing_image_count']);
        $this->assertSame(0, $result['duplicate_sku_group_count']);
        $this->assertSame(0, $result['duplicate_sku_row_count']);
        $this->assertSame(
            ['gid://shopify/Product/400'],
            array_column($result['findings'], 'product_gid')
        );
        $this->assertSame('missing_image', $result['findings'][0]['finding_type']);
    }

    public function test_it_counts_duplicate_variants_in_one_product_and_keeps_original_sku(): void
    {
        $result = (new CatalogAuditJsonlParser)->parse($this->jsonl([
            [
                'id' => 'gid://shopify/Product/500',
                'legacyResourceId' => '500',
                'title' => 'Two Pack',
                'handle' => 'two-pack',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'gid://shopify/ProductVariant/501',
                'legacyResourceId' => '501',
                'title' => 'One',
                'sku' => ' ABC-9 ',
                '__parentId' => 'gid://shopify/Product/500',
            ],
            [
                'id' => 'gid://shopify/ProductVariant/502',
                'legacyResourceId' => '502',
                'title' => 'Two',
                'sku' => 'abc-9',
                '__parentId' => 'gid://shopify/Product/500',
            ],
        ]), $this->shop());

        $this->assertSame(1, $result['missing_image_count']);
        $this->assertSame(1, $result['duplicate_sku_group_count']);
        $this->assertSame(2, $result['duplicate_sku_row_count']);
        $skus = array_column($this->duplicateFindings($result['findings']), 'sku');
        sort($skus);
        $this->assertSame([' ABC-9 ', 'abc-9'], $skus);
    }

    public function test_it_produces_the_same_findings_when_jsonl_order_changes(): void
    {
        $rows = [
            [
                'id' => 'gid://shopify/Product/600',
                'legacyResourceId' => '600',
                'title' => 'Stable Lamp',
                'handle' => 'stable-lamp',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'gid://shopify/ProductVariant/601',
                'legacyResourceId' => '601',
                'title' => 'Default',
                'sku' => 'stable',
                '__parentId' => 'gid://shopify/Product/600',
            ],
            [
                'id' => 'gid://shopify/ProductVariant/602',
                'legacyResourceId' => '602',
                'title' => 'Alt',
                'sku' => ' STABLE ',
                '__parentId' => 'gid://shopify/Product/600',
            ],
        ];

        $forward = (new CatalogAuditJsonlParser)->parse($this->jsonl($rows), $this->shop());
        $reverse = (new CatalogAuditJsonlParser)->parse($this->jsonl(array_reverse($rows)), $this->shop());

        $this->assertSame($forward, $reverse);
    }

    public function test_it_rejects_invalid_json_instead_of_reconciling_a_partial_snapshot(): void
    {
        $jsonl = $this->jsonl([
            [
                'id' => 'gid://shopify/Product/700',
                'legacyResourceId' => '700',
                'title' => 'Valid Lamp',
                'handle' => 'valid-lamp',
                'status' => 'ACTIVE',
            ],
        ])."\n{invalid-json";

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid Shopify catalog JSONL record at line 2.');

        (new CatalogAuditJsonlParser)->parse($jsonl, $this->shop());
    }

    /** @dataProvider malformedRecordProvider */
    public function test_it_rejects_malformed_records_instead_of_silently_ignoring_them(array $record, string $message): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        (new CatalogAuditJsonlParser)->parse($this->jsonl([$record]), $this->shop());
    }

    public static function malformedRecordProvider(): array
    {
        return [
            'missing id' => [
                ['status' => 'ACTIVE'],
                'missing a valid id at line 1',
            ],
            'malformed gid' => [
                ['id' => 'gid://shopify/Product/not-a-number', 'status' => 'ACTIVE'],
                'invalid resource GID at line 1',
            ],
            'invalid child parent' => [
                ['id' => 'gid://shopify/ProductImage/1', '__parentId' => 'gid://shopify/Product/nope'],
                'invalid product parent at line 1',
            ],
            'missing product status' => [
                ['id' => 'gid://shopify/Product/1'],
                'product has an invalid status at line 1',
            ],
        ];
    }

    public function test_it_rejects_an_orphan_child_from_an_incomplete_snapshot(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('child at line 1 references a product missing from the snapshot');

        (new CatalogAuditJsonlParser)->parse($this->jsonl([
            [
                'id' => 'gid://shopify/ProductVariant/10',
                'sku' => 'orphan',
                '__parentId' => 'gid://shopify/Product/999',
            ],
        ]), $this->shop());
    }

    public function test_it_deduplicates_duplicate_variant_nodes_by_valid_variant_gid(): void
    {
        $result = (new CatalogAuditJsonlParser)->parse($this->jsonl([
            [
                'id' => 'gid://shopify/Product/800',
                'legacyResourceId' => '800',
                'title' => 'First Lamp',
                'handle' => 'first-lamp',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'gid://shopify/Product/801',
                'legacyResourceId' => '801',
                'title' => 'Second Lamp',
                'handle' => 'second-lamp',
                'status' => 'ACTIVE',
            ],
            [
                'id' => 'gid://shopify/ProductVariant/8010',
                'legacyResourceId' => '8010',
                'title' => 'Default',
                'sku' => 'shared-sku',
                '__parentId' => 'gid://shopify/Product/800',
            ],
            [
                'id' => 'gid://shopify/ProductVariant/8010',
                'legacyResourceId' => '8010',
                'title' => 'Default',
                'sku' => 'shared-sku',
                '__parentId' => 'gid://shopify/Product/800',
            ],
            [
                'id' => 'gid://shopify/ProductVariant/8011',
                'legacyResourceId' => '8011',
                'title' => 'Default',
                'sku' => ' shared-sku ',
                '__parentId' => 'gid://shopify/Product/801',
            ],
        ]), $this->shop());

        $duplicates = $this->duplicateFindings($result['findings']);

        $this->assertSame(1, $result['duplicate_sku_group_count']);
        $this->assertSame(2, $result['duplicate_sku_row_count']);
        $this->assertCount(2, $duplicates);
        $this->assertSame(
            [
                'duplicate_sku:b00552d8e107f8ba220d566689404dae437d12e8d1b6f72273c99dd1e69ba49f',
                'duplicate_sku:ff71daa46a6450b07e6ddc3cccf798afa0ae7208d2dc2d054aa4cd9762ed9188',
            ],
            array_column($duplicates, 'fingerprint')
        );
    }

    public function test_it_uses_the_first_hostname_label_for_admin_urls(): void
    {
        $result = (new CatalogAuditJsonlParser)->parse($this->jsonl([
            [
                'id' => 'gid://shopify/Product/900',
                'legacyResourceId' => '900',
                'title' => 'Nested Domain Lamp',
                'handle' => 'nested-domain-lamp',
                'status' => 'ACTIVE',
            ],
        ]), new Shop(['domain' => 'first.second.myshopify.com']));

        $this->assertSame(
            'https://admin.shopify.com/store/first/products/900',
            $result['findings'][0]['shopify_admin_url']
        );
    }

    private function shop(): Shop
    {
        return new Shop(['domain' => 'example.myshopify.com']);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function jsonl(array $rows): string
    {
        return implode("\n", array_map(static fn (array $row): string => json_encode($row), $rows));
    }

    /** @param array<int, array<string, mixed>> $findings */
    private function finding(array $findings, string $type): array
    {
        foreach ($findings as $finding) {
            if ($finding['finding_type'] === $type) {
                return $finding;
            }
        }

        self::fail("Finding {$type} was not found.");
    }

    /** @param array<int, array<string, mixed>> $findings */
    private function duplicateFindings(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            static fn (array $finding): bool => $finding['finding_type'] === 'duplicate_sku'
        ));
    }
}
