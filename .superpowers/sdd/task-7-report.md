# Task 7 Report: Scheduler Contract

## Status

IMPLEMENTED

## Files Changed

- `app/Console/Kernel.php`
- `tests/Unit/CatalogAuditScheduleContractTest.php`
- `.superpowers/sdd/task-7-report.md`

The scheduler now includes a separate `catalog-audit:scan` entry at `01:00` in
`Europe/Bucharest`, guarded by `withoutOverlapping()` and configured with
`runInBackground()`. The existing midnight
`shopify:bulk-missing-images --send-minicrm` chain was left unchanged.

## TDD Evidence

The static scheduler contract test was written first. Before the scheduler
entry was added, this command failed as expected:

```bash
php artisan test tests/Unit/CatalogAuditScheduleContractTest.php
```

The failure was limited to the missing `catalog-audit:scan` chain; the
midnight-preservation assertion passed. After the schedule was added, the
same test passed with 2 tests and 4 assertions.

## Verification

Passed:

```bash
php artisan test tests/Unit/CatalogAuditScheduleContractTest.php
php -l app/Console/Kernel.php
php -l tests/Unit/CatalogAuditScheduleContractTest.php
./vendor/bin/pint --test tests/Unit/CatalogAuditScheduleContractTest.php
git diff --check -- app/Console/Kernel.php tests/Unit/CatalogAuditScheduleContractTest.php
```

The combined Pint check for both owned PHP files reports pre-existing
`Kernel.php` issues (`single_line_comment_spacing` and old method-chain
indentation). Those unrelated lines were not reformatted so the existing
schedules remain logically unchanged.

Production migration, cache clearing, queue restart, command smoke scans, and
commits were intentionally not performed.
