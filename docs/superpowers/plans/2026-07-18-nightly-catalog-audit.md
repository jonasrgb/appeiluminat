# Nightly Shopify Catalog Audit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a nightly, read-only audit that lists active products without images and active variants with duplicate SKUs for five Shopify shops.

**Architecture:** A dedicated Shopify Bulk Operation retrieves active products, images, and variants once per shop. A pure JSONL parser calculates both finding types, an atomic reconciler replaces only that shop's last successful snapshot, and authenticated dashboard routes display current findings by shop and report type.

**Tech Stack:** Laravel 10, PHP 8.1+, MySQL, Laravel queue/scheduler, Shopify Admin GraphQL Bulk Operations, Blade, custom CSS, PHPUnit 10.

## Global Constraints

- Shopify access is read-only; the feature must not execute any mutation other than `bulkOperationRunQuery`, which only starts a read query.
- Audit only `ACTIVE` products.
- Ignore blank SKUs and compare non-empty SKUs after `trim` plus case-insensitive normalization.
- Run exactly these shops: eiluminat, Lustreled, Powerled, Industrial, and eIluminat Bulgaria. Exclude backup.
- Schedule at `01:00` in `Europe/Bucharest`.
- Preserve findings from the last successful run when a later run fails.
- Keep the existing midnight MiniCRM command unchanged.
- Do not commit automatically; the workspace contains unrelated live changes and the user did not request a commit.

## File Structure

- Create `config/catalog_audit.php`: ordered shop map and runtime limits.
- Create `database/migrations/2026_07_18_200000_create_catalog_audit_runs_table.php`: per-shop run state.
- Create `database/migrations/2026_07_18_200100_create_catalog_audit_findings_table.php`: current finding rows.
- Create `app/Models/CatalogAuditRun.php`: run statuses, casts, shop relation.
- Create `app/Models/CatalogAuditFinding.php`: finding types, casts, run/shop relations.
- Create `app/Services/Shopify/CatalogAuditJsonlParser.php`: pure JSONL-to-findings transformation.
- Create `app/Services/Shopify/CatalogAuditBulkService.php`: start, poll, and download one Shopify bulk query.
- Create `app/Services/Shopify/CatalogAuditReconciler.php`: atomic snapshot replacement.
- Create `app/Jobs/Shopify/RunCatalogAuditForShop.php`: globally serialized per-shop orchestration.
- Create `app/Console/Commands/RunCatalogAuditCommand.php`: atomic independent dispatch on the dedicated `database_catalog_audit` connection and manual shop selection.
- Create `app/Http/Controllers/CatalogAuditController.php`: report queries and shop resolution.
- Create `resources/views/catalog-audit/index.blade.php`: both report layouts with custom CSS.
- Modify `routes/web.php`: authenticated report routes.
- Modify `resources/views/layouts/navigation.blade.php`: catalog audit navigation item.
- Modify `app/Console/Kernel.php`: nightly schedule.
- Create focused unit and feature tests listed below.

---

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
'connection' => 'database_catalog_audit',
'queue' => 'catalog_audit',
'timeout_seconds' => 1200,
'poll_seconds' => 5,
```

- [ ] **Step 5: Run the schema contract test**

Expected: pass.

---

### Task 2: Pure Shopify JSONL Parser

**Files:**
- Create: `app/Services/Shopify/CatalogAuditJsonlParser.php`
- Test: `tests/Unit/CatalogAuditJsonlParserTest.php`

**Interfaces:**
- Produces: `parse(string $jsonl, Shop $shop): array`.
- Return shape:

```php
[
    'findings' => array<int, array<string, mixed>>,
    'missing_image_count' => int,
    'duplicate_sku_group_count' => int,
    'duplicate_sku_row_count' => int,
]
```

- [ ] **Step 1: Write failing parser tests**

Cover order-independent JSONL containing:

```json
{"id":"gid://shopify/Product/100","legacyResourceId":"100","title":"Lamp A","handle":"lamp-a","status":"ACTIVE"}
{"id":"gid://shopify/ProductVariant/1001","legacyResourceId":"1001","title":"Red","sku":" SKU-1 ","__parentId":"gid://shopify/Product/100"}
{"id":"gid://shopify/Product/200","legacyResourceId":"200","title":"Lamp B","handle":"lamp-b","status":"ACTIVE"}
{"id":"gid://shopify/ProductImage/900","__parentId":"gid://shopify/Product/200"}
{"id":"gid://shopify/ProductVariant/2001","legacyResourceId":"2001","title":"Default","sku":"sku-1","__parentId":"gid://shopify/Product/200"}
```

Assert product 100 is missing an image, product 200 is not, and the two variants form one duplicate group. Add cases for DRAFT products, blank SKUs, duplicate variants in one product, image rows arriving before product rows, and a video/media row that is not a product image.

- [ ] **Step 2: Run parser tests and verify they fail**

Run:

```bash
php artisan test tests/Unit/CatalogAuditJsonlParserTest.php
```

- [ ] **Step 3: Implement the parser**

Classify records by GID resource type, retain only active product IDs, and normalize SKU with:

```php
private function normalizeSku(?string $sku): ?string
{
    $trimmed = trim((string) $sku);

    return $trimmed === '' ? null : mb_strtolower($trimmed);
}
```

Build stable finding fingerprints from product GID for missing images and normalized SKU plus variant GID for duplicate rows. Generate Admin links using the first segment of the `.myshopify.com` domain.

- [ ] **Step 4: Run parser tests**

Expected: all parser tests pass without a database or network connection.

---

### Task 3: Bulk Operation Reader

**Files:**
- Create: `app/Services/Shopify/CatalogAuditBulkService.php`
- Test: `tests/Feature/CatalogAuditBulkServiceTest.php`

**Interfaces:**
- Consumes: active `Shop`, timeout, and poll interval.
- Produces: `downloadSnapshot(Shop $shop, int $timeoutSeconds, int $pollSeconds): string`.

- [ ] **Step 1: Write failing HTTP-fake tests**

Fake three boundaries: start mutation returns an operation ID, current operation reaches `COMPLETED`, and the signed result URL returns JSONL. Assert the mutation embeds a bulk query for active products, `images(first: 1)`, and variants with SKU. Add failure tests for GraphQL user errors, terminal failed status, timeout, and missing result URL.

- [ ] **Step 2: Run the bulk service tests and verify they fail**

Run:

```bash
php artisan test tests/Feature/CatalogAuditBulkServiceTest.php
```

- [ ] **Step 3: Implement the read-only bulk service**

Use the shop's configured API version and access token. The embedded bulk query must be equivalent to:

```graphql
{
  products(query: "status:active") {
    edges {
      node {
        id
        legacyResourceId
        title
        handle
        status
        images(first: 1) { edges { node { id } } }
        variants { edges { node { id legacyResourceId title sku } } }
      }
    }
  }
}
```

Only `bulkOperationRunQuery` is a mutation; it starts a read operation and does not modify catalog data. Poll `currentBulkOperation(type: QUERY)`, reject operation-ID changes, and download `url` with fallback to `partialDataUrl` only when Shopify reports completion.

- [ ] **Step 4: Run the bulk service tests**

Expected: all HTTP-fake tests pass with no live Shopify calls.

---

### Task 4: Atomic Finding Reconciliation

**Files:**
- Create: `app/Services/Shopify/CatalogAuditReconciler.php`
- Test: `tests/Feature/CatalogAuditReconcilerTest.php`

**Interfaces:**
- Consumes: `reconcile(CatalogAuditRun $run, array $parsed): void`.
- Updates: current findings for only `$run->shop_id` and final run counts/status.

- [ ] **Step 1: Build an isolated SQLite test schema**

In the test setup, switch the test process to SQLite `:memory:`, purge the connection, and create only `shops`, `catalog_audit_runs`, and `catalog_audit_findings`. Do not use `RefreshDatabase`, because an existing historical migration contains MySQL-only SQL.

- [ ] **Step 2: Write failing reconciliation tests**

Assert:

- first successful run inserts findings;
- second successful run removes a missing product that is no longer present;
- an empty successful run clears all findings for that shop;
- findings for another shop remain untouched;
- duplicate fingerprints update metadata instead of creating duplicates;
- throwing before `reconcile()` leaves old findings unchanged.

- [ ] **Step 3: Run the tests and verify they fail**

Run:

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test tests/Feature/CatalogAuditReconcilerTest.php
```

- [ ] **Step 4: Implement transaction-based reconciliation**

Within `DB::transaction()`:

```php
CatalogAuditFinding::upsert(
    $rows,
    ['shop_id', 'finding_type', 'fingerprint'],
    $mutableColumns
);

CatalogAuditFinding::query()
    ->where('shop_id', $run->shop_id)
    ->where('last_seen_run_id', '!=', $run->id)
    ->delete();
```

When the new finding list is empty, delete all findings for that shop explicitly. Mark the run completed only inside the same transaction.

- [ ] **Step 5: Run reconciliation tests**

Expected: pass and prove snapshot replacement is shop-scoped and atomic.

---

### Task 5: Independently Queued Jobs and Manual Command

**Files:**
- Create: `app/Jobs/Shopify/RunCatalogAuditForShop.php`
- Create: `app/Console/Commands/RunCatalogAuditCommand.php`
- Test: `tests/Unit/CatalogAuditCommandContractTest.php`
- Test: `tests/Feature/RunCatalogAuditForShopTest.php`

**Interfaces:**
- Job constructor: `__construct(public int $runId, public int $shopId)`.
- Command: `catalog-audit:scan {--shop= : Shop slug, domain, or numeric ID}`.

- [ ] **Step 1: Write failing orchestration tests**

Assert the command creates queued runs in configured order and independently inserts one `catalog_audit` database-queue job for every run. Decode each payload to prove it has no chained successor and carries `tries = 0`. For a single `--shop`, assert only that active configured shop is dispatched. Assert an unknown or excluded shop fails before dispatch. Trigger the second `JobQueueing` event to fail and assert the enclosing transaction rolls back both run rows and any previously inserted `jobs` rows.

For the job, fake the bulk reader, parser, and reconciler. Assert queued to running to completed flow. On a caught scan exception, assert only the matching run becomes failed, stale findings remain, and another shop's queued run remains queued. Assert `failed(Throwable)` follows the same scope for timeout/fatal errors and treats completed runs idempotently. Assert queue `WithoutOverlapping` middleware uses the shared global `catalog-audit-global` key, a 60-second release delay, and a 2400-second expiry.

- [ ] **Step 2: Run orchestration tests and verify they fail**

Run:

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test \
  tests/Unit/CatalogAuditCommandContractTest.php \
  tests/Feature/RunCatalogAuditForShopTest.php
```

- [ ] **Step 3: Implement the job**

Use `tries = 0`, `timeout = 1800`, and fail-on-timeout. Queue attempts remain unlimited because `WithoutOverlapping` releases jobs that cannot acquire the global mutex. The job must catch a scan exception, update only its matching queued/running run to failed with a bounded error string, log context, and return without application-level release/retry. `failed(Throwable)` repeats that update best-effort for timeout/fatal handling and returns when the run is already completed. Add queue middleware equivalent to:

```php
public function middleware(): array
{
    return [
            (new WithoutOverlapping('catalog-audit-global'))
                ->releaseAfter(60)
                ->expireAfter(2400)
                ->shared(),
    ];
}
```

- [ ] **Step 4: Implement the command**

After validating the dedicated `database_catalog_audit` connection uses the application database, resolve only active shops present in `config('catalog_audit.shops')`. Inside one `DB::transaction()`, create one queued run per shop in configuration order, create its `catalog_audit` job, and call `Bus::dispatch($job)` immediately. A dispatch exception must escape the transaction so it rolls back all created runs and database queue rows. Do not call `Bus::chain`.

- [ ] **Step 5: Run orchestration tests**

Expected: pass.

---

### Task 6: Authenticated Dashboard Pages

**Files:**
- Create: `app/Http/Controllers/CatalogAuditController.php`
- Create: `resources/views/catalog-audit/index.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/navigation.blade.php`
- Test: `tests/Feature/CatalogAuditControllerTest.php`

**Interfaces:**
- Routes: `catalog-audit.missing-images` and `catalog-audit.duplicate-skus`.
- Controller methods: `missingImages(Request $request, string $shop)` and `duplicateSkus(Request $request, string $shop)`.

- [ ] **Step 1: Write failing controller/query tests**

Using the isolated SQLite schema, assert unauthenticated requests redirect to login, invalid shop slugs return 404, missing-image rows paginate at 25, duplicate SKU groups paginate at 10 groups, search filters IDs/title/handle/SKU, and results never include another shop.

- [ ] **Step 2: Run the controller tests and verify they fail**

Run:

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test tests/Feature/CatalogAuditControllerTest.php
```

- [ ] **Step 3: Implement routes and controller queries**

Add under the existing `auth` middleware. Do not enable `MustVerifyEmail` globally as part of this feature:

```php
Route::get('/dashboard/catalog-audit/{shop}/missing-images', ...)
    ->name('catalog-audit.missing-images');
Route::get('/dashboard/catalog-audit/{shop}/duplicate-skus', ...)
    ->name('catalog-audit.duplicate-skus');
```

Resolve shop slugs through config and query current findings. For duplicate pagination, paginate a grouped `normalized_sku` subquery, then fetch all affected rows for only the groups on the current page.

- [ ] **Step 4: Build the Blade view with custom CSS**

Use one view receiving `reportType`. Include store tabs, report tabs, scan status, current counts, search, direct Shopify buttons, empty states, and pagination. Use explicit light-theme CSS classes prefixed `audit-`; do not nest cards or depend on Tailwind dark mode for table visibility.

- [ ] **Step 5: Add desktop and mobile navigation links**

Link to the first configured shop's missing-image page and mark it active for `catalog-audit.*` routes.

- [ ] **Step 6: Run controller tests and Blade compilation check**

Run:

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test tests/Feature/CatalogAuditControllerTest.php
php artisan view:cache
php artisan view:clear
```

Expected: tests pass and Blade compiles.

---

### Task 7: Scheduler and Production Verification

**Files:**
- Modify: `app/Console/Kernel.php`
- Test: `tests/Unit/CatalogAuditScheduleContractTest.php`

**Interfaces:**
- Scheduled command: `catalog-audit:scan`.

- [ ] **Step 1: Write the failing scheduler contract test**

Assert `Kernel.php` schedules the command with:

```php
->dailyAt('01:00')
->timezone('Europe/Bucharest')
->withoutOverlapping()
->runInBackground();
```

- [ ] **Step 2: Run it and verify failure**

Run:

```bash
php artisan test tests/Unit/CatalogAuditScheduleContractTest.php
```

- [ ] **Step 3: Add the schedule without modifying the midnight command**

Insert a separate schedule entry in `app/Console/Kernel.php`.

- [ ] **Step 4: Run the complete focused test set**

Run parser, bulk service, reconciliation, orchestration, controller, and schedule tests with SQLite overrides only for database-backed tests. Then run PHP syntax checks and `git diff --check`.

- [ ] **Step 5: Run the production migration**

After tests pass:

```bash
TELESCOPE_ENABLED=false php artisan migrate --force
```

Expected: exactly the two catalog audit migrations apply.

- [ ] **Step 6: Clear framework caches and restart the queue worker**

Run:

```bash
php artisan optimize:clear
pm2 restart laravel-queue
pm2 describe laravel-queue
```

Verify a dedicated worker runs `queue:work database_catalog_audit --queue=catalog_audit`. Do not add this queue to an existing worker because long scans must remain isolated.

- [ ] **Step 7: Validate command discovery and schedule**

Run:

```bash
php artisan list --raw | rg '^catalog-audit:scan'
php artisan schedule:list | rg 'catalog-audit:scan|01:00|Europe/Bucharest'
```

- [ ] **Step 8: Perform a read-only manual smoke scan for one shop**

Dispatch only eIluminat:

```bash
php artisan catalog-audit:scan --shop=eiluminat
```

Monitor the `catalog_audit` job and verify one completed run plus dashboard findings. Do not run all five manually unless the single-shop smoke test is successful.

## Final Verification

- No Shopify product, variant, image, SKU, metafield, publication, or status mutation exists in the feature.
- A successful new run removes resolved findings.
- A failed new run preserves previous findings and shows failure metadata.
- Duplicate SKU groups contain only active products and normalized non-empty SKUs.
- Backup is absent from config, command resolution, tabs, and routes.
- The midnight MiniCRM command remains byte-for-byte unchanged.
- The nightly command is visible at 01:00 Europe/Bucharest.
