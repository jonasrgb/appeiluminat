### Task 4: Safety, Scope, and Regression Verification

**Files:**
- Modify: `tests/Unit/ReplicateProductUpdateIdentityContractTest.php`
- Verify only: `app/Jobs/ReplicateProductUpdateToShop.php`
- Verify only: BEM tests listed below

**Interfaces:**
- Consumes: completed bootstrap flow from Tasks 1-3.
- Produces: proof that unsafe targets remain untouched, BG is excluded, BEM is unchanged, and production workers can be restarted safely.

- [ ] **Step 1: Add strict scope and no-fallback contract assertions**

Add these assertions:

```php
public function test_legacy_bootstrap_has_no_mutable_identity_fallback_and_bg_exits_first(): void
{
    $bootstrap = $this->methodSource('bootstrapLegacyVariantIdentity');
    $handle = $this->methodSource('handle');

    foreach (['sku', 'title', 'handle', 'options_key', 'position', 'price'] as $forbidden) {
        $this->assertStringNotContainsString($forbidden, strtolower($bootstrap));
    }

    $bgExit = strpos($handle, "eiluminat-bg.myshopify.com");
    $strictSync = strpos($handle, '$this->syncVariantsStrict(');
    $this->assertNotFalse($bgExit);
    $this->assertNotFalse($strictSync);
    $this->assertLessThan($strictSync, $bgExit);
}

public function test_unsafe_bootstrap_returns_before_shopify_mutations(): void
{
    $source = $this->methodSource('bootstrapLegacyVariantIdentity');
    $unsafe = strpos($source, "$decision['status'] === 'unsafe'");
    $setMetafield = strpos($source, '$this->setParentVariantMetafield(');
    $productSet = strpos($source, '$this->productSetVariantStructure(');

    $this->assertNotFalse($unsafe);
    $this->assertNotFalse($setMetafield);
    $this->assertNotFalse($productSet);
    $this->assertLessThan($setMetafield, $unsafe);
    $this->assertLessThan($productSet, $unsafe);
}
```

- [ ] **Step 2: Run all targeted automated tests**

Run:

```bash
php artisan test \
  tests/Unit/LegacyParentVariantBootstrapPolicyTest.php \
  tests/Unit/ReplicateProductUpdateIdentityContractTest.php \
  tests/Unit/ReplicateProductCreateIdentityContractTest.php \
  tests/Feature/ShopifyParentIdentityResolverTest.php \
  tests/Feature/BemWatermarkFlowTest.php \
  tests/Feature/BemWatermarkUpdateManifestTest.php
```

Expected: PASS with no network requests escaping Laravel HTTP fakes.

- [ ] **Step 3: Run syntax and formatting verification**

Run:

```bash
php -l app/Services/Shopify/LegacyParentVariantBootstrapPolicy.php
php -l app/Jobs/ReplicateProductUpdateToShop.php
php -l tests/Unit/LegacyParentVariantBootstrapPolicyTest.php
php -l tests/Unit/ReplicateProductUpdateIdentityContractTest.php
vendor/bin/pint --test app/Services/Shopify/LegacyParentVariantBootstrapPolicy.php app/Jobs/ReplicateProductUpdateToShop.php tests/Unit/LegacyParentVariantBootstrapPolicyTest.php tests/Unit/ReplicateProductUpdateIdentityContractTest.php
```

Expected: all syntax checks print `No syntax errors detected`; Pint exits 0.

- [ ] **Step 4: Review the final diff for scope**

Run:

```bash
git diff --check
git diff -- app/Services/Shopify/LegacyParentVariantBootstrapPolicy.php app/Jobs/ReplicateProductUpdateToShop.php tests/Unit/LegacyParentVariantBootstrapPolicyTest.php tests/Unit/ReplicateProductUpdateIdentityContractTest.php
```

Expected: no whitespace errors; no changes to BEM services, BG jobs, routes, migrations, or frontend files.

- [ ] **Step 5: Commit the safety tests**

```bash
git add tests/Unit/ReplicateProductUpdateIdentityContractTest.php
git commit -m "test: guard legacy parentvariant bootstrap scope"
```

