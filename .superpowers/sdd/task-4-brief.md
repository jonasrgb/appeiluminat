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

