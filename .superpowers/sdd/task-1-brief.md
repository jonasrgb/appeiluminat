### Task 1: Audit Schema and Models

**Files:**
- Create: `database/migrations/2026_07_18_200000_create_catalog_audit_runs_table.php`
- Create: `database/migrations/2026_07_18_200100_create_catalog_audit_findings_table.php`
- Create: `app/Models/CatalogAuditRun.php`
- Create: `app/Models/CatalogAuditFinding.php`
- Create: `config/catalog_audit.php`
- Test: `tests/Unit/CatalogAuditSchemaContractTest.php`

**Interfaces:**
- Produces: `CatalogAuditRun::{STATUS_QUEUED, STATUS_RUNNING, STATUS_COMPLETED, STATUS_FAILED}`.
- Produces: `CatalogAuditFinding::{TYPE_MISSING_IMAGE, TYPE_DUPLICATE_SKU}`.
- Produces: ordered `config('catalog_audit.shops')` map of route slug to Shopify domain.

- [ ] **Step 1: Write the schema contract test**

Assert that both migrations contain the required foreign keys, run status/count fields, finding identity fields, and unique key `shop_id + finding_type + fingerprint`. Assert that the config contains exactly the five approved domains and excludes `eiluminatbackup.myshopify.com`.

- [ ] **Step 2: Run the test and verify it fails**

Run:

```bash
php artisan test tests/Unit/CatalogAuditSchemaContractTest.php
```

Expected: failure because models, migrations, and config do not exist.

- [ ] **Step 3: Create the migrations**

The runs table must include:

```php
$table->id();
$table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
$table->string('status', 20)->index();
$table->timestamp('started_at')->nullable();
$table->timestamp('finished_at')->nullable();
$table->unsignedInteger('missing_image_count')->default(0);
$table->unsignedInteger('duplicate_sku_group_count')->default(0);
$table->unsignedInteger('duplicate_sku_row_count')->default(0);
$table->text('error_message')->nullable();
$table->timestamps();
```

The findings table must include product and optional variant identity, normalized SKU, Shopify Admin URL, `last_seen_run_id`, and:

```php
$table->unique(
    ['shop_id', 'finding_type', 'fingerprint'],
    'catalog_audit_finding_identity_unique'
);
$table->index(['shop_id', 'finding_type', 'normalized_sku']);
```

- [ ] **Step 4: Create focused models and config**

Use fillable arrays for every persisted field, datetime casts for run timestamps and `last_seen_at`, and these ordered shop slugs:

```php
'shops' => [
    'eiluminat' => 'eiluminat.myshopify.com',
    'lustreled' => 'lustreled.myshopify.com',
    'powerleds' => 'powerleds-ro.myshopify.com',
    'industrial' => 'iluminat-industrial.myshopify.com',
    'bulgaria' => 'eiluminat-bg.myshopify.com',
],
'queue' => 'bulk_ops',
'timeout_seconds' => 1200,
'poll_seconds' => 5,
```

- [ ] **Step 5: Run the schema contract test**

Expected: pass.

---

