### Task 5: Sequential Jobs and Manual Command

**Files:**
- Create: `app/Jobs/Shopify/RunCatalogAuditForShop.php`
- Create: `app/Console/Commands/RunCatalogAuditCommand.php`
- Test: `tests/Unit/CatalogAuditCommandContractTest.php`
- Test: `tests/Feature/RunCatalogAuditForShopTest.php`

**Interfaces:**
- Job constructor: `__construct(public int $runId, public int $shopId)`.
- Command: `catalog-audit:scan {--shop= : Shop slug, domain, or numeric ID}`.

- [ ] **Step 1: Write failing orchestration tests**

Assert the command creates queued runs in configured order and dispatches a `Bus::chain` of jobs on `bulk_ops`. For a single `--shop`, assert only that active configured shop is dispatched. Assert an unknown or excluded shop fails before dispatch.

For the job, fake the bulk reader, parser, and reconciler. Assert queued to running to completed flow. On first exception, assert the job is released for retry and does not dispatch the next chain item. On final exception, assert the run is failed and the method returns so Laravel can continue the chain. Assert the job exposes queue `WithoutOverlapping` middleware keyed by shop ID, with a finite expiry, so a manually-triggered audit cannot overlap the nightly audit for the same shop.

- [ ] **Step 2: Run orchestration tests and verify they fail**

Run:

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test \
  tests/Unit/CatalogAuditCommandContractTest.php \
  tests/Feature/RunCatalogAuditForShopTest.php
```

- [ ] **Step 3: Implement the job**

Use `tries = 2`, `backoff = [60]`, `timeout = 1800`, and fail-on-timeout. The job must catch its final exception, update the run to failed with a bounded error string, log context, and return. On the first attempt it must call `$this->release(60)` and return without finalizing the chain. Add queue middleware equivalent to:

```php
public function middleware(): array
{
    return [
        (new WithoutOverlapping('catalog-audit-shop-'.$this->shopId))
            ->releaseAfter(60)
            ->expireAfter(2400),
    ];
}
```

- [ ] **Step 4: Implement the command**

Resolve only active shops present in `config('catalog_audit.shops')`, create one queued run per shop, construct jobs in config order, set each to `bulk_ops`, and dispatch with `Bus::chain($jobs)->dispatch()`.

- [ ] **Step 5: Run orchestration tests**

Expected: pass.

---

