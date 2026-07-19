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

Add under existing `auth` and `verified` middleware:

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

