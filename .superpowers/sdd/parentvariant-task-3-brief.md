### Task 3: One-Time Declarative Multi-Variant Bootstrap

**Files:**
- Modify: `app/Jobs/ReplicateProductUpdateToShop.php:340-840`
- Modify: `tests/Unit/ReplicateProductUpdateIdentityContractTest.php`

**Interfaces:**
- Consumes: the `replace_structure` policy action and existing `productSetVariantStructure()` mutation builder.
- Produces: a fully managed Shopify variant state with exactly one target variant per source parent ID.

- [ ] **Step 1: Add failing tests for declarative bootstrap and idempotency**

Add to `ReplicateProductUpdateIdentityContractTest`:

```php
public function test_legacy_structure_bootstrap_uses_product_set_without_retained_variant_gid(): void
{
    $source = $this->methodSource('bootstrapLegacyVariantIdentity');

    $this->assertStringContainsString("'replace_structure'", $source);
    $this->assertStringContainsString('$this->productSetVariantStructure(', $source);
    $this->assertStringContainsString('allowCompatibleOptionStructure: true', $source);
    $this->assertStringContainsString('$this->assertBootstrapIdentityState(', $source);
    $this->assertStringContainsString('$this->replaceVariantMirrorsFromVerifiedState(', $source);
}

public function test_product_set_can_be_explicitly_enabled_for_legacy_default_variant_bootstrap(): void
{
    $source = $this->methodSource('productSetVariantStructure');

    $this->assertStringContainsString('bool $allowCompatibleOptionStructure = false', $source);
    $this->assertStringContainsString('!$allowCompatibleOptionStructure', $source);
}

public function test_bootstrap_policy_is_checked_only_when_unmanaged_variants_exist(): void
{
    $source = $this->methodSource('bootstrapLegacyVariantIdentity');

    $this->assertStringContainsString("$decision['status'] === 'not_needed'", $source);
    $this->assertStringContainsString("'status' => 'not_needed'", $source);
}
```

- [ ] **Step 2: Run focused tests and confirm failure**

Run:

```bash
php artisan test tests/Unit/ReplicateProductUpdateIdentityContractTest.php
```

Expected: FAIL because the replacement branch and explicit compatible-option override do not exist.

- [ ] **Step 3: Permit the existing productSet helper only for the explicit bootstrap call**

Extend the method signature:

```php
private function productSetVariantStructure(
    Shop $shop,
    string $productGid,
    array $sourceById,
    array $sourceOptions,
    array $targetOptions,
    array $verifiedMirrors,
    bool $allowCompatibleOptionStructure = false
): array {
```

Change its compatibility guard to:

```php
if (!$allowCompatibleOptionStructure
    && $this->mixedReplacementHasCompatibleOptions($sourceOptions, $targetOptions)
) {
    throw new \RuntimeException(
        'productSet structural replacement was called for compatible options'
    );
}
```

Existing mixed-replacement calls omit the new argument and preserve current behavior.

- [ ] **Step 4: Implement the declarative replacement branch**

Insert before the unsupported-action exception in
`bootstrapLegacyVariantIdentity()`:

```php
if ($decision['action'] === 'replace_structure') {
    $targetOptions = $this->fetchTargetOptions($target, $mirror->target_product_gid);

    $this->productSetVariantStructure(
        $target,
        $mirror->target_product_gid,
        $sourceById,
        $sourceOptions,
        $targetOptions,
        [],
        allowCompatibleOptionStructure: true
    );

    $verifiedState = $identityResolver->targetVariantState(
        $target,
        $mirror->target_product_gid
    );
    $this->assertBootstrapIdentityState($verifiedState, array_keys($sourceById));
    $this->replaceVariantMirrorsFromVerifiedState(
        $mirror,
        $sourceById,
        $verifiedState
    );

    Log::notice('Variant identity structure bootstrapped declaratively', [
        'source_product_id' => $this->sourceProductId,
        'target_shop' => $target->domain,
        'target_product_gid' => $mirror->target_product_gid,
        'source_variant_ids' => array_map('intval', array_keys($sourceById)),
    ]);

    return [
        'status' => 'bootstrapped',
        'target_state' => $verifiedState,
        'reset_mirrors' => true,
    ];
}
```

The empty retained-mirror array is mandatory: no existing target variant may be assigned to a source variant by a mutable field.

- [ ] **Step 5: Run policy and contract tests**

Run:

```bash
php artisan test tests/Unit/LegacyParentVariantBootstrapPolicyTest.php tests/Unit/ReplicateProductUpdateIdentityContractTest.php
```

Expected: PASS.

- [ ] **Step 6: Run identity resolver regression tests**

Run:

```bash
php artisan test tests/Feature/ShopifyParentIdentityResolverTest.php
```

Expected: PASS; unique, missing, ambiguous, unmanaged, and duplicate parent-ID behavior is unchanged.

- [ ] **Step 7: Commit declarative bootstrap**

```bash
git add app/Jobs/ReplicateProductUpdateToShop.php tests/Unit/ReplicateProductUpdateIdentityContractTest.php
git commit -m "feat: rebuild legacy target variant identity once"
```

