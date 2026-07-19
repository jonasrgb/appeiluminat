# Parentvariant Task 2 Report

## Status

Implemented and committed the one-to-one legacy parentvariant bootstrap in the live update job. The Task 2 contract tests pass after the implementation. The prescribed combined Task 1+2 command still has one pre-existing, out-of-scope queue retry-window failure caused by the current `config/queue.php` value of `90` being lower than the existing jobs' `840` second timeouts.

## Changed Files

- `app/Jobs/ReplicateProductUpdateToShop.php`
  - Injects `LegacyParentVariantBootstrapPolicy` into `handle()` and the strict variant sync.
  - Runs legacy bootstrap before the unmanaged/ambiguous identity guard.
  - Attaches the one eligible unmanaged target variant to the sole source variant, re-reads identity state, validates the postcondition, and replaces variant mirrors transactionally.
- `tests/Unit/ReplicateProductUpdateIdentityContractTest.php`
  - Covers dependency order, bootstrap-before-guard ordering, and one-to-one reuse without structural deletion.

## Commit

`f742d82 feat: bootstrap single legacy parentvariant on update`

## Test Commands And Results

1. `php artisan test tests/Unit/ReplicateProductUpdateIdentityContractTest.php`
   - RED before implementation: 4 failed, 15 passed.
   - The three new Task 2 contracts failed because the policy dependency and bootstrap method were absent. The pre-existing queue retry-window contract also failed because `retry_after` is `90` and job timeouts are `840`.

2. `php -l app/Jobs/ReplicateProductUpdateToShop.php && php -l tests/Unit/ReplicateProductUpdateIdentityContractTest.php`
   - PASS: no syntax errors in either file.

3. `php artisan test tests/Unit/LegacyParentVariantBootstrapPolicyTest.php tests/Unit/ReplicateProductUpdateIdentityContractTest.php`
   - 1 failed, 25 passed, 89 assertions.
   - All Task 1 and Task 2 policy/bootstrap contracts passed. The sole failure was the existing queue retry-window contract described above.

4. `php artisan test tests/Unit/LegacyParentVariantBootstrapPolicyTest.php && php artisan test tests/Unit/ReplicateProductUpdateIdentityContractTest.php --filter='/(test_update_job_requires_identity_resolver_and_legacy_bootstrap_policy|test_legacy_bootstrap_runs_before_the_unmanaged_variant_guard|test_single_legacy_variant_is_reused_without_product_set)/'`
   - PASS: 7 policy tests (19 assertions) and 3 Task 2 contract tests (8 assertions).

## Concerns

- `config/queue.php` is currently uncommitted and out of Task 2 scope. Its database `retry_after` value (`90`) makes the existing queue retry-window contract fail against the jobs' `840` second timeouts.
- PHPUnit reports that the XML configuration uses a deprecated schema.

## Reviewer Finding Fix

### Commit

`dff84d0 fix: extend database queue retry window`

### Change

Changed only the default `database` connection's `retry_after` line in `config/queue.php` to `(int) env('DB_QUEUE_RETRY_AFTER', 900)`. The existing `database_catalog_audit` connection and all other queue connections were not included in the commit.

### Test Commands And Results

1. `php artisan test tests/Unit/ReplicateProductUpdateIdentityContractTest.php --filter=test_database_queue_retry_window_exceeds_replication_job_timeouts`
   - PASS: 1 test passed, 2 assertions.

2. `php artisan test tests/Unit/LegacyParentVariantBootstrapPolicyTest.php tests/Unit/ReplicateProductUpdateIdentityContractTest.php`
   - PASS: 26 tests passed, 90 assertions.

Both commands emitted the existing PHPUnit XML deprecated-schema warning.
