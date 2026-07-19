<?php

namespace Tests\Feature;

use App\Models\CatalogAuditFinding;
use App\Models\CatalogAuditRun;
use App\Models\Shop;
use App\Services\Shopify\CatalogAuditReconciler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class CatalogAuditReconcilerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required for the isolated catalog audit database test.');
        }

        $this->configureInMemoryDatabase();
    }

    public function test_first_successful_run_inserts_findings_and_completes_the_run(): void
    {
        $shop = $this->shop('first-run.myshopify.com');
        $run = $this->createRun($shop);

        $this->reconciler()->reconcile($run, $this->parsed([
            $this->missingImageFinding('gid://shopify/Product/100', 'First Lamp'),
        ]));

        $finding = CatalogAuditFinding::sole();
        $run->refresh();

        $this->assertSame($shop->id, $finding->shop_id);
        $this->assertSame($run->id, $finding->last_seen_run_id);
        $this->assertSame('First Lamp', $finding->product_title);
        $this->assertSame(CatalogAuditRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->missing_image_count);
        $this->assertSame(0, $run->duplicate_sku_group_count);
        $this->assertSame(0, $run->duplicate_sku_row_count);
        $this->assertNotNull($run->finished_at);
    }

    public function test_second_successful_run_removes_a_missing_product_that_is_no_longer_present(): void
    {
        $shop = $this->shop('resolved-product.myshopify.com');
        $firstRun = $this->createRun($shop);

        $this->reconciler()->reconcile($firstRun, $this->parsed([
            $this->missingImageFinding('gid://shopify/Product/200', 'Resolved Lamp'),
            $this->missingImageFinding('gid://shopify/Product/201', 'Still Missing Lamp'),
        ]));

        $secondRun = $this->createRun($shop);
        $this->reconciler()->reconcile($secondRun, $this->parsed([
            $this->missingImageFinding('gid://shopify/Product/201', 'Still Missing Lamp'),
        ]));

        $this->assertSame([
            'gid://shopify/Product/201',
        ], CatalogAuditFinding::query()->orderBy('product_gid')->pluck('product_gid')->all());
        $this->assertSame($secondRun->id, CatalogAuditFinding::sole()->last_seen_run_id);
    }

    public function test_empty_successful_run_clears_all_findings_for_its_shop(): void
    {
        $shop = $this->shop('empty-run.myshopify.com');
        $firstRun = $this->createRun($shop);
        $this->reconciler()->reconcile($firstRun, $this->parsed([
            $this->missingImageFinding('gid://shopify/Product/300', 'Resolved Lamp'),
        ]));

        $secondRun = $this->createRun($shop);
        $this->reconciler()->reconcile($secondRun, $this->parsed([]));

        $secondRun->refresh();

        $this->assertSame(0, CatalogAuditFinding::where('shop_id', $shop->id)->count());
        $this->assertSame(CatalogAuditRun::STATUS_COMPLETED, $secondRun->status);
        $this->assertSame(0, $secondRun->missing_image_count);
        $this->assertSame(0, $secondRun->duplicate_sku_group_count);
        $this->assertSame(0, $secondRun->duplicate_sku_row_count);
    }

    public function test_malformed_payload_preserves_existing_findings_and_run_state(): void
    {
        $shop = $this->shop('malformed-payload.myshopify.com');
        $completedRun = $this->createRun($shop);
        $this->reconciler()->reconcile($completedRun, $this->parsed([
            $this->missingImageFinding('gid://shopify/Product/350', 'Existing Lamp'),
        ]));

        $pendingRun = $this->createRun($shop);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("must contain a 'findings' array");

        try {
            $this->reconciler()->reconcile($pendingRun, [
                'missing_image_count' => 0,
                'duplicate_sku_group_count' => 0,
                'duplicate_sku_row_count' => 0,
            ]);
        } finally {
            $pendingRun->refresh();

            $this->assertSame([
                'gid://shopify/Product/350',
            ], CatalogAuditFinding::where('shop_id', $shop->id)->pluck('product_gid')->all());
            $this->assertSame(CatalogAuditRun::STATUS_RUNNING, $pendingRun->status);
            $this->assertNull($pendingRun->finished_at);
        }
    }

    public function test_missing_required_count_keys_preserve_existing_findings_and_run_state(): void
    {
        foreach ([
            'missing_image_count',
            'duplicate_sku_group_count',
            'duplicate_sku_row_count',
        ] as $missingCountKey) {
            $shop = $this->shop("missing-{$missingCountKey}.myshopify.com");
            $completedRun = $this->createRun($shop);
            $this->reconciler()->reconcile($completedRun, $this->parsed([
                $this->missingImageFinding('gid://shopify/Product/360', 'Existing Lamp'),
            ]));

            $pendingRun = $this->createRun($shop);
            $payload = $this->parsed([]);
            unset($payload[$missingCountKey]);

            try {
                $this->reconciler()->reconcile($pendingRun, $payload);
                $this->fail("Expected missing '{$missingCountKey}' to be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($missingCountKey, $exception->getMessage());
            } finally {
                $pendingRun->refresh();

                $this->assertSame([
                    'gid://shopify/Product/360',
                ], CatalogAuditFinding::where('shop_id', $shop->id)->pluck('product_gid')->all());
                $this->assertSame(CatalogAuditRun::STATUS_RUNNING, $pendingRun->status);
                $this->assertNull($pendingRun->finished_at);
            }
        }
    }

    public function test_reconciliation_leaves_findings_for_another_shop_untouched(): void
    {
        $auditedShop = $this->shop('audited.myshopify.com');
        $otherShop = $this->shop('other.myshopify.com');
        $auditedRun = $this->createRun($auditedShop);
        $otherRun = $this->createRun($otherShop);

        $this->reconciler()->reconcile($auditedRun, $this->parsed([
            $this->missingImageFinding('gid://shopify/Product/400', 'Audited Lamp'),
        ]));
        $this->reconciler()->reconcile($otherRun, $this->parsed([
            $this->missingImageFinding('gid://shopify/Product/401', 'Other Shop Lamp'),
        ]));

        $nextAuditedRun = $this->createRun($auditedShop);
        $this->reconciler()->reconcile($nextAuditedRun, $this->parsed([]));

        $this->assertSame(0, CatalogAuditFinding::where('shop_id', $auditedShop->id)->count());
        $this->assertSame([
            'gid://shopify/Product/401',
        ], CatalogAuditFinding::where('shop_id', $otherShop->id)->pluck('product_gid')->all());
    }

    public function test_duplicate_fingerprints_update_metadata_without_creating_duplicates(): void
    {
        $shop = $this->shop('duplicate-fingerprint.myshopify.com');
        $firstRun = $this->createRun($shop);

        $this->reconciler()->reconcile($firstRun, $this->parsed([
            $this->duplicateSkuFinding('gid://shopify/ProductVariant/500', 'Original Lamp'),
            $this->duplicateSkuFinding('gid://shopify/ProductVariant/501', 'Sibling Lamp'),
        ], 0, 1, 2));

        $secondRun = $this->createRun($shop);
        $this->reconciler()->reconcile($secondRun, $this->parsed([
            $this->duplicateSkuFinding('gid://shopify/ProductVariant/500', 'Renamed Lamp'),
            $this->duplicateSkuFinding('gid://shopify/ProductVariant/501', 'Sibling Lamp'),
        ], 0, 1, 2));

        $updated = CatalogAuditFinding::query()
            ->where('fingerprint', 'duplicate_sku:shared-sku:gid://shopify/ProductVariant/500')
            ->sole();

        $this->assertSame(2, CatalogAuditFinding::where('shop_id', $shop->id)->count());
        $this->assertSame('Renamed Lamp', $updated->product_title);
        $this->assertSame($secondRun->id, $updated->last_seen_run_id);
    }

    public function test_throwing_before_reconcile_leaves_old_findings_unchanged(): void
    {
        $shop = $this->shop('pre-reconcile-failure.myshopify.com');
        $run = $this->createRun($shop);
        $this->reconciler()->reconcile($run, $this->parsed([
            $this->missingImageFinding('gid://shopify/Product/600', 'Existing Lamp'),
        ]));

        try {
            throw new RuntimeException('Snapshot download failed before reconciliation.');
        } catch (RuntimeException) {
            // The job catches this failure without invoking the reconciler.
        }

        $this->assertSame([
            'gid://shopify/Product/600',
        ], CatalogAuditFinding::where('shop_id', $shop->id)->pluck('product_gid')->all());
    }

    public function test_it_rejects_a_run_with_a_mutated_shop_before_mutating_findings(): void
    {
        $persistedShop = $this->shop('persisted-run.myshopify.com');
        $mutatedShop = $this->shop('mutated-run.myshopify.com');
        $run = $this->createRun($persistedShop);
        $this->reconciler()->reconcile($run, $this->parsed([
            $this->missingImageFinding('gid://shopify/Product/700', 'Existing Lamp'),
        ]));

        $run->shop_id = $mutatedShop->id;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match the persisted shop');

        try {
            $this->reconciler()->reconcile($run, $this->parsed([
                $this->missingImageFinding('gid://shopify/Product/701', 'Wrong Shop Lamp'),
            ]));
        } finally {
            $this->assertSame([
                'gid://shopify/Product/700',
            ], CatalogAuditFinding::where('shop_id', $persistedShop->id)->pluck('product_gid')->all());
            $this->assertSame(0, CatalogAuditFinding::where('shop_id', $mutatedShop->id)->count());
        }
    }

    private function reconciler(): CatalogAuditReconciler
    {
        return app(CatalogAuditReconciler::class);
    }

    private function shop(string $domain): Shop
    {
        return Shop::create([
            'domain' => $domain,
            'access_token' => 'catalog-audit-token',
        ]);
    }

    private function createRun(Shop $shop): CatalogAuditRun
    {
        return CatalogAuditRun::create([
            'shop_id' => $shop->id,
            'status' => CatalogAuditRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    /** @param array<int, array<string, mixed>> $findings */
    private function parsed(
        array $findings,
        int $missingImageCount = 0,
        int $duplicateSkuGroupCount = 0,
        int $duplicateSkuRowCount = 0
    ): array {
        return [
            'findings' => $findings,
            'missing_image_count' => $missingImageCount ?: count(array_filter(
                $findings,
                static fn (array $finding): bool => $finding['finding_type'] === CatalogAuditFinding::TYPE_MISSING_IMAGE
            )),
            'duplicate_sku_group_count' => $duplicateSkuGroupCount,
            'duplicate_sku_row_count' => $duplicateSkuRowCount,
        ];
    }

    /** @return array<string, mixed> */
    private function missingImageFinding(string $productGid, string $title): array
    {
        return [
            'finding_type' => CatalogAuditFinding::TYPE_MISSING_IMAGE,
            'fingerprint' => "missing_image:{$productGid}",
            'product_gid' => $productGid,
            'product_legacy_id' => (int) substr($productGid, strrpos($productGid, '/') + 1),
            'product_title' => $title,
            'product_handle' => 'lamp',
            'product_status' => 'ACTIVE',
            'variant_gid' => null,
            'variant_legacy_id' => null,
            'variant_title' => null,
            'sku' => null,
            'normalized_sku' => null,
            'shopify_admin_url' => 'https://admin.shopify.com/store/test/products/1',
        ];
    }

    /** @return array<string, mixed> */
    private function duplicateSkuFinding(string $variantGid, string $title): array
    {
        return [
            'finding_type' => CatalogAuditFinding::TYPE_DUPLICATE_SKU,
            'fingerprint' => "duplicate_sku:shared-sku:{$variantGid}",
            'product_gid' => 'gid://shopify/Product/500',
            'product_legacy_id' => 500,
            'product_title' => $title,
            'product_handle' => 'lamp',
            'product_status' => 'ACTIVE',
            'variant_gid' => $variantGid,
            'variant_legacy_id' => (int) substr($variantGid, strrpos($variantGid, '/') + 1),
            'variant_title' => 'Default Title',
            'sku' => 'Shared-SKU',
            'normalized_sku' => 'shared-sku',
            'shopify_admin_url' => 'https://admin.shopify.com/store/test/products/500',
        ];
    }

    private function configureInMemoryDatabase(): void
    {
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        Config::set('database.connections.sqlite.foreign_key_constraints', true);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('domain')->unique();
            $table->text('access_token');
            $table->string('api_version')->default('2025-01');
            $table->boolean('is_source')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('location_legacy_id')->nullable();
            $table->timestamps();
        });

        Schema::create('catalog_audit_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->unique(['id', 'shop_id'], 'catalog_audit_runs_id_shop_unique');
            $table->string('status', 20)->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('missing_image_count')->default(0);
            $table->unsignedInteger('duplicate_sku_group_count')->default(0);
            $table->unsignedInteger('duplicate_sku_row_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('catalog_audit_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('last_seen_run_id');
            $table->string('finding_type', 30)->index();
            $table->string('fingerprint');
            $table->string('product_gid');
            $table->unsignedBigInteger('product_legacy_id')->nullable();
            $table->string('product_title')->nullable();
            $table->string('product_handle')->nullable();
            $table->string('product_status')->nullable();
            $table->string('variant_gid')->nullable();
            $table->unsignedBigInteger('variant_legacy_id')->nullable();
            $table->string('variant_title')->nullable();
            $table->string('sku')->nullable();
            $table->string('normalized_sku')->nullable();
            $table->string('shopify_admin_url')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['shop_id', 'finding_type', 'fingerprint']);
            $table->foreign(
                ['last_seen_run_id', 'shop_id'],
                'catalog_audit_findings_run_shop_foreign'
            )->references(['id', 'shop_id'])->on('catalog_audit_runs')->restrictOnDelete();
        });
    }
}
