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

Verify the worker listens to `bulk_ops`. If it does not, update only its queue list before starting any audit.

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

Monitor the `bulk_ops` job and verify one completed run plus dashboard findings. Do not run all five manually unless the single-shop smoke test is successful.

## Final Verification

- No Shopify product, variant, image, SKU, metafield, publication, or status mutation exists in the feature.
- A successful new run removes resolved findings.
- A failed new run preserves previous findings and shows failure metadata.
- Duplicate SKU groups contain only active products and normalized non-empty SKUs.
- Backup is absent from config, command resolution, tabs, and routes.
- The midnight MiniCRM command remains byte-for-byte unchanged.
- The nightly command is visible at 01:00 Europe/Bucharest.
