# Task 4 Report: Atomic Finding Reconciliation

## Status

IMPLEMENTED_WITH_SQLITE_VERIFICATION_BLOCKED

## Files Changed

- `app/Services/Shopify/CatalogAuditReconciler.php`
- `tests/Feature/CatalogAuditReconcilerTest.php`

The reconciler builds shop-scoped finding rows from parser output, validates that the in-memory run's shop matches the persisted run before entering the transaction, then atomically upserts current findings, removes stale findings for that shop, and marks the run completed with its final counts. Empty snapshots explicitly delete all findings for the audited shop. The run update is also constrained by both run ID and shop ID inside the transaction so a concurrent shop reassignment rolls back the reconciliation.

The feature test creates only `shops`, `catalog_audit_runs`, and `catalog_audit_findings` in a purged SQLite `:memory:` connection. It does not use `RefreshDatabase` and does not connect to MySQL. Coverage includes initial insertion, resolved-finding deletion, empty snapshot clearing, shop scoping, duplicate-fingerprint metadata updates, pre-reconciliation failures preserving prior findings, and the application-level run/shop consistency guard.

## TDD Evidence

The reconciliation test was written before `CatalogAuditReconciler` was created. The required red command could not reach PHPUnit because this PHP runtime has no SQLite PDO driver:

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test --colors=never tests/Feature/CatalogAuditReconcilerTest.php
```

Exact output, before implementation:

```text
   Illuminate\Database\QueryException

  could not find driver (Connection: sqlite, SQL: PRAGMA foreign_keys = ON;)

  at vendor/laravel/framework/src/Illuminate/Database/Connection.php:829
    825▕             $this->getName(), $query, $this->prepareBindings($bindings), $e
    826▕         );
    827▕     }
    828▕
  ➜ 829▕     throw new QueryException(
    830▕         $this->getName(), $query, $this->prepareBindings($bindings), $e
    831▕     );
    832▕ }
    833▕ }

  1   [internal]:0
      Illuminate\Foundation\Application::Illuminate\Foundation\{closure}()
      +32 vendor frames

  34  app/Providers/RouteServiceProvider.php:44
      Illuminate\Support\Facades\Facade::__callStatic()
```

The same command was re-run after implementation and produced the same exact driver error. It therefore cannot provide a green test result in this environment.

## Verification

Command:

```bash
vendor/bin/pint --test app/Services/Shopify/CatalogAuditReconciler.php tests/Feature/CatalogAuditReconcilerTest.php
php -l app/Services/Shopify/CatalogAuditReconciler.php
php -l tests/Feature/CatalogAuditReconcilerTest.php
git diff --check
```

Exact output:

```text
  ..

  ──────────────────────────────────────────────────────────────────── Laravel
    PASS   ........................................................... 2 files

No syntax errors detected in app/Services/Shopify/CatalogAuditReconciler.php
No syntax errors detected in tests/Feature/CatalogAuditReconcilerTest.php
```

`git diff --check` exited successfully with no output. No commit was created.

## Concerns

- `pdo_sqlite` is unavailable: `PDO::getAvailableDrivers()` returns only `mysql`, and `extension_loaded('pdo_sqlite')` returns `false`. Laravel opens the configured SQLite connection during application boot, before the test's `setUp()` can skip the test. Installing or enabling the PHP SQLite extension is required before the requested focused test can be executed.
- The task expressly prohibits connecting to live MySQL, so no fallback database test was attempted.
- PHPUnit also has an existing deprecated XML configuration warning when tests reach PHPUnit; the current blocker occurs earlier during Laravel boot.
- The worktree was already dirty with unrelated user/concurrent-task changes. They were not modified or reverted.

## Fix Report

Review findings were addressed in the reconciler, isolated feature schema, migrations, and static schema contract.

- `CatalogAuditReconciler` now validates the complete parser payload before any finding or run mutation. `findings` must be explicitly present as an array; an explicit empty array remains a successful empty scan. Every finding must be an array, and `missing_image_count`, `duplicate_sku_group_count`, and `duplicate_sku_row_count` must each be present as non-negative integers. Malformed payloads throw `InvalidArgumentException` instead of defaulting to an empty scan or zero counts.
- The feature suite includes regressions that preserve previous findings and leave the pending run `running` when `findings` is missing, and when each required count key is missing.
- `catalog_audit_runs` now has the named unique key `(id, shop_id)`. `catalog_audit_findings` preserves its shop FK and identity key, while its named composite FK `(last_seen_run_id, shop_id)` references `(id, shop_id)` on runs with `RESTRICT` deletion. The isolated SQLite schema mirrors this relationship.
- `CatalogAuditSchemaContractTest` statically verifies both sides of the composite relationship. Its red phase failed on the missing unique/composite FK; after the migration changes it passed: `4 passed (53 assertions)`.

Final verification:

```text
php artisan test --colors=never tests/Unit/CatalogAuditSchemaContractTest.php
PASS: 4 tests, 53 assertions

php -l [all five owned PHP files]
No syntax errors detected

vendor/bin/pint --test [all five owned PHP files]
PASS: 5 files
```

The focused reconciliation suite was re-run with SQLite and remains blocked during Laravel bootstrap because this host has no `pdo_sqlite` driver (`could not find driver` at `PRAGMA foreign_keys = ON`). No live MySQL test was attempted, per task constraints. Consequently, a database-backed in-transaction rollback test could not be added or executed here; the transaction remains covered structurally by the reconciler implementation and the new malformed-payload regressions are ready to run once SQLite PDO is available. No commit was created.
