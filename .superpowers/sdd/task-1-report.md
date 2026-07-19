# Task 1 Report: Audit Schema and Models

## Status

DONE_WITH_CONCERNS

## Files Changed

Task 1 implementation and contract test:

- `database/migrations/2026_07_18_200000_create_catalog_audit_runs_table.php`
- `database/migrations/2026_07_18_200100_create_catalog_audit_findings_table.php`
- `app/Models/CatalogAuditRun.php`
- `app/Models/CatalogAuditFinding.php`
- `config/catalog_audit.php`
- `tests/Unit/CatalogAuditSchemaContractTest.php`

The migrations define shop-scoped run state and current findings, including the required foreign keys, status/count fields, product and optional variant identity, SKU fields, Admin URL, timestamps, unique finding identity, and lookup index. The models expose the required status/type constants, fillable persisted fields, datetime casts, and shop/run relationships. The config contains the five approved shops in order, the `bulk_ops` queue, and the requested timeout/poll values.

No unrelated files were edited and no commit was created.

## TDD Verification

Red phase command:

```text
php artisan test tests/Unit/CatalogAuditSchemaContractTest.php
```

Result before implementation: failed as expected because the two migrations and two models were absent and `catalog_audit` config values were missing.

Green phase command:

```text
php artisan test tests/Unit/CatalogAuditSchemaContractTest.php
```

Result:

```text
PASS  Tests/Unit/CatalogAuditSchemaContractTest
Tests: 4 passed (47 assertions)
Duration: 0.18s
```

Additional verification:

- `php -l` passed for all six Task 1 PHP files.
- `git diff --check` passed.

## Concerns

- PHPUnit emits the existing warning that `phpunit.xml` uses a deprecated XML configuration schema. This is unrelated to Task 1 and was not changed.
- The focused contract test validates migration source and model/config contracts; migrations were not applied to a live database to avoid altering the dirty workspace/database state. A later integration task should exercise the migrations against its isolated SQLite schema as specified.

## Fix Report

The `last_seen_run_id` foreign key now uses Laravel's `restrictOnDelete()` policy so deleting a catalog audit run cannot cascade-delete current findings. The schema contract test positively asserts `restrictOnDelete()` and rejects the previous cascade policy. The unrelated composite shop/run FK concern was not changed.

Focused test command:

```text
php artisan test --colors=never tests/Unit/CatalogAuditSchemaContractTest.php
```

Exact test output:

```text

  WARN  Your XML configuration validates against a deprecated schema. Migrate your XML configuration using "--migrate-configuration"!

   PASS  Tests\Unit\CatalogAuditSchemaContractTest
  ✓ catalog audit run migration contains the required schema contract    0.10s
  ✓ catalog audit finding migration contains identity and metadata fiel… 0.01s
  ✓ models expose the required constants fillable fields and casts       0.01s
  ✓ catalog audit config has the approved ordered shops and runtime lim… 0.01s

  Tests:    4 passed (48 assertions)
  Duration: 0.18s
```

PHP lint output:

```text
No syntax errors detected in database/migrations/2026_07_18_200100_create_catalog_audit_findings_table.php
No syntax errors detected in tests/Unit/CatalogAuditSchemaContractTest.php
```

## Schema Follow-up

Task 4 review follow-up added the MySQL-compatible run/shop consistency constraint: `catalog_audit_runs` now declares the named unique key `(id, shop_id)`, and `catalog_audit_findings` now declares the named composite restricted foreign key `(last_seen_run_id, shop_id)` referencing `catalog_audit_runs(id, shop_id)`. The independent `last_seen_run_id` foreign key was replaced; the existing `shop_id` foreign key and finding identity unique key remain intact. The static schema contract passed after this update with `4 tests, 53 assertions`.
