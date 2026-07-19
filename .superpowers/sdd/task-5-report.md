# Task 5 Report: Independently Queued Jobs and Manual Command

## Status

IMPLEMENTED_WITH_SQLITE_INTEGRATION_VERIFICATION_BLOCKED

## Files Changed

- `app/Jobs/Shopify/RunCatalogAuditForShop.php`
- `app/Console/Commands/RunCatalogAuditCommand.php`
- `config/queue.php`
- `tests/Unit/CatalogAuditCommandContractTest.php`
- `tests/Feature/RunCatalogAuditForShopTest.php`
- `docs/superpowers/specs/2026-07-18-nightly-catalog-audit-design.md`
- `docs/superpowers/plans/2026-07-18-nightly-catalog-audit.md`
- `.superpowers/sdd/task-5-report.md`

The command resolves only active configured shops, preserving configuration order. It accepts a configured slug, configured domain, or active configured numeric ID, rejects unknown or excluded input before creating a run, and validates the dedicated `database_catalog_audit` connection is on the application database. Inside one transaction, it creates each queued run and independently dispatches its `catalog_audit` job. A queue insertion failure rolls back both run rows and database queue rows. Its queue name and 2700-second reservation window are isolated from both the existing database queue and the pre-existing `bulk_ops` workload.

Every job uses the shared `catalog-audit-global` `WithoutOverlapping` mutex with `releaseAfter(60)`, `expireAfter(2400)`, and `shared()`. `tries = 0` allows lock-contention releases without exhausting a worker attempt budget. The job validates the persisted run/shop relationship, active state, and configuration membership before setting its run to `running`, downloading/parsing/reconciling. A caught scan error marks only its matching queued/running run failed and returns; there is no application-level release/retry. The `failed(Throwable)` callback best-effort applies the same queued/running-only update for timeout or fatal worker failures and does nothing to a completed run. Reconciliation is never invoked before a failed bulk download, leaving stale findings untouched.

## TDD Evidence

The Task 5 contract test was changed before the command/job refactor. The red command was:

```bash
php artisan test tests/Unit/CatalogAuditCommandContractTest.php --testdox
```

It failed on the expected legacy behavior: `Bus::chain`, `tries = 2`, a per-shop mutex, and chain continuation. After the refactor, the same contract suite passed with 3 tests and 37 assertions.

## Verification

The guarded SQLite integration suite creates only `shops`, `catalog_audit_runs`, `catalog_audit_findings`, and `jobs` in an in-memory connection. It does not connect to MySQL or Shopify. It covers independent payloads, rollback of runs and queue rows after a second dispatch failure, global lock configuration, caught-error isolation, and idempotent timeout/fatal handling.

```bash
php artisan test tests/Unit/CatalogAuditCommandContractTest.php tests/Feature/RunCatalogAuditForShopTest.php --testdox
php -l app/Console/Commands/RunCatalogAuditCommand.php
php -l app/Jobs/Shopify/RunCatalogAuditForShop.php
php -l config/queue.php
php -l tests/Unit/CatalogAuditCommandContractTest.php
php -l tests/Feature/RunCatalogAuditForShopTest.php
vendor/bin/pint --test app/Console/Commands/RunCatalogAuditCommand.php app/Jobs/Shopify/RunCatalogAuditForShop.php config/queue.php tests/Unit/CatalogAuditCommandContractTest.php tests/Feature/RunCatalogAuditForShopTest.php
git diff --check
```

Results: the static contract suite passed with 3 tests and 37 assertions. PHP lint and Pint passed for all five Task 5 PHP files, and `git diff --check` passed. The feature suite skipped 10 tests because `pdo_sqlite` is unavailable, so no SQLite connection, live MySQL query, or Shopify request was made. PHPUnit emitted the repository's existing deprecated XML schema warning. No commit was created.
