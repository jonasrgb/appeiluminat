<?php

namespace Tests\Unit;

use App\Models\CatalogAuditFinding;
use App\Models\CatalogAuditRun;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CatalogAuditSchemaContractTest extends TestCase
{
    public function test_catalog_audit_run_migration_contains_the_required_schema_contract(): void
    {
        $migration = file_get_contents(
            base_path('database/migrations/2026_07_18_200000_create_catalog_audit_runs_table.php')
        );

        $this->assertNotFalse($migration);
        $this->assertStringContainsString("Schema::create('catalog_audit_runs'", $migration);
        $this->assertStringContainsString("foreignId('shop_id')->constrained('shops')->cascadeOnDelete()", $migration);
        $this->assertStringContainsString("['id', 'shop_id']", $migration);
        $this->assertStringContainsString("'catalog_audit_runs_id_shop_unique'", $migration);
        $this->assertStringContainsString("string('status', 20)->index()", $migration);
        $this->assertStringContainsString("timestamp('started_at')->nullable()", $migration);
        $this->assertStringContainsString("timestamp('finished_at')->nullable()", $migration);
        $this->assertStringContainsString("unsignedInteger('missing_image_count')->default(0)", $migration);
        $this->assertStringContainsString("unsignedInteger('duplicate_sku_group_count')->default(0)", $migration);
        $this->assertStringContainsString("unsignedInteger('duplicate_sku_row_count')->default(0)", $migration);
        $this->assertStringContainsString("text('error_message')->nullable()", $migration);
    }

    public function test_catalog_audit_finding_migration_contains_identity_and_metadata_fields(): void
    {
        $migration = file_get_contents(
            base_path('database/migrations/2026_07_18_200100_create_catalog_audit_findings_table.php')
        );

        $this->assertNotFalse($migration);
        $this->assertStringContainsString("Schema::create('catalog_audit_findings'", $migration);
        $this->assertStringContainsString("foreignId('shop_id')->constrained('shops')->cascadeOnDelete()", $migration);
        $this->assertStringContainsString("foreignId('last_seen_run_id')", $migration);
        $this->assertStringContainsString("['last_seen_run_id', 'shop_id']", $migration);
        $this->assertStringContainsString("'catalog_audit_findings_run_shop_foreign'", $migration);
        $this->assertStringContainsString("->references(['id', 'shop_id'])->on('catalog_audit_runs')->restrictOnDelete()", $migration);
        $this->assertStringNotContainsString("constrained('catalog_audit_runs')", $migration);

        foreach ([
            "string('finding_type', 30)",
            "string('fingerprint')",
            "string('product_gid')",
            "unsignedBigInteger('product_legacy_id')->nullable()",
            "string('product_title')->nullable()",
            "string('product_handle')->nullable()",
            "string('product_status')->nullable()",
            "string('variant_gid')->nullable()",
            "unsignedBigInteger('variant_legacy_id')->nullable()",
            "string('variant_title')->nullable()",
            "string('sku')->nullable()",
            "string('normalized_sku')->nullable()",
            "string('shopify_admin_url')->nullable()",
            "timestamp('last_seen_at')->nullable()",
        ] as $field) {
            $this->assertStringContainsString($field, $migration);
        }

        $this->assertStringContainsString("['shop_id', 'finding_type', 'fingerprint']", $migration);
        $this->assertStringContainsString("'catalog_audit_finding_identity_unique'", $migration);
        $this->assertStringContainsString(
            "['shop_id', 'finding_type', 'normalized_sku']",
            $migration
        );
    }

    public function test_models_expose_the_required_constants_fillable_fields_and_casts(): void
    {
        $this->assertSame('queued', CatalogAuditRun::STATUS_QUEUED);
        $this->assertSame('running', CatalogAuditRun::STATUS_RUNNING);
        $this->assertSame('completed', CatalogAuditRun::STATUS_COMPLETED);
        $this->assertSame('failed', CatalogAuditRun::STATUS_FAILED);

        $this->assertSame('missing_image', CatalogAuditFinding::TYPE_MISSING_IMAGE);
        $this->assertSame('duplicate_sku', CatalogAuditFinding::TYPE_DUPLICATE_SKU);

        $run = new CatalogAuditRun;
        $this->assertSame([
            'shop_id',
            'status',
            'started_at',
            'finished_at',
            'missing_image_count',
            'duplicate_sku_group_count',
            'duplicate_sku_row_count',
            'error_message',
        ], $run->getFillable());
        $this->assertSame('datetime', $run->getCasts()['started_at']);
        $this->assertSame('datetime', $run->getCasts()['finished_at']);

        $finding = new CatalogAuditFinding;
        $this->assertSame([
            'shop_id',
            'last_seen_run_id',
            'finding_type',
            'fingerprint',
            'product_gid',
            'product_legacy_id',
            'product_title',
            'product_handle',
            'product_status',
            'variant_gid',
            'variant_legacy_id',
            'variant_title',
            'sku',
            'normalized_sku',
            'shopify_admin_url',
            'last_seen_at',
        ], $finding->getFillable());
        $this->assertSame('datetime', $finding->getCasts()['last_seen_at']);
    }

    public function test_catalog_audit_config_has_the_approved_ordered_shops_and_runtime_limits(): void
    {
        $this->assertSame([
            'eiluminat' => 'eiluminat.myshopify.com',
            'lustreled' => 'lustreled.myshopify.com',
            'powerleds' => 'powerleds-ro.myshopify.com',
            'industrial' => 'iluminat-industrial.myshopify.com',
            'bulgaria' => 'eiluminat-bg.myshopify.com',
        ], Config::get('catalog_audit.shops'));
        $this->assertSame('database_catalog_audit', Config::get('catalog_audit.connection'));
        $this->assertSame('catalog_audit', Config::get('catalog_audit.queue'));
        $this->assertSame(1200, Config::get('catalog_audit.timeout_seconds'));
        $this->assertSame(5, Config::get('catalog_audit.poll_seconds'));
        $this->assertNotContains('eiluminatbackup.myshopify.com', Config::get('catalog_audit.shops'));
    }
}
