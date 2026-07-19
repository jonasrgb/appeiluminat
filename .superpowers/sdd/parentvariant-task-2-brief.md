### Task 2: One-to-One Parentvariant Bootstrap

**Files:**
- Modify: `app/Jobs/ReplicateProductUpdateToShop.php:1-340`
- Modify: `tests/Unit/ReplicateProductUpdateIdentityContractTest.php`

**Interfaces:**
- Consumes: `LegacyParentVariantBootstrapPolicy::decide()`, `ShopifyParentIdentityResolver::targetVariantState()`, and `setParentVariantMetafield()`.
- Produces: `ReplicateProductUpdateToShop::bootstrapLegacyVariantIdentity(...): array{status:string,target_state:array,reset_mirrors:bool}`.

- [ ] **Step 1: Add failing contract tests for dependency order and one-to-one behavior**

Add these tests to `ReplicateProductUpdateIdentityContractTest`:

```php
public function test_update_job_requires_identity_resolver_and_legacy_bootstrap_policy(): void
{
    $handle = new ReflectionMethod(ReplicateProductUpdateToShop::class, 'handle');
    $parameters = $handle->getParameters();

    $this->assertSame(
        [
            ShopifyParentIdentityResolver::class,
            \App\Services\Shopify\LegacyParentVariantBootstrapPolicy::class,
        ],
        array_map(fn ($parameter) => $parameter->getType()?->getName(), $parameters)
    );
}

public function test_legacy_bootstrap_runs_before_the_unmanaged_variant_guard(): void
{
    $source = $this->methodSource('syncVariantsStrict');

    $bootstrap = strpos($source, '$this->bootstrapLegacyVariantIdentity(');
    $guard = strpos($source, "'incomplete_parentvariant_mapping'");

    $this->assertNotFalse($bootstrap);
    $this->assertNotFalse($guard);
    $this->assertLessThan($guard, $bootstrap);
}

public function test_single_legacy_variant_is_reused_without_product_set(): void
{
    $source = $this->methodSource('bootstrapLegacyVariantIdentity');

    $this->assertStringContainsString("'attach_single'", $source);
    $this->assertStringContainsString('$this->setParentVariantMetafield(', $source);
    $this->assertStringContainsString("'legacy_bootstrap_single'", $source);
    $this->assertStringNotContainsString('productVariantsBulkDelete', $source);
}
```

Replace the old one-parameter handle assertion with the new two-dependency assertion instead of keeping both.

- [ ] **Step 2: Run the focused contract tests and confirm failure**

Run:

```bash
php artisan test tests/Unit/ReplicateProductUpdateIdentityContractTest.php
```

Expected: FAIL because the policy is not injected and `bootstrapLegacyVariantIdentity()` does not exist.

- [ ] **Step 3: Inject the policy and call bootstrap before the current guard**

Add the import:

```php
use App\Services\Shopify\LegacyParentVariantBootstrapPolicy;
use Illuminate\Support\Facades\DB;
```

Change `handle()` and the strict sync call:

```php
public function handle(
    ShopifyParentIdentityResolver $identityResolver,
    LegacyParentVariantBootstrapPolicy $bootstrapPolicy
): void {
    // Existing handle body remains unchanged.
}
```

```php
if (!$this->syncVariantsStrict(
    $identityResolver,
    $bootstrapPolicy,
    $mirror,
    $target,
    $source
)) {
    return;
}
```

Add the policy argument to `syncVariantsStrict()`. Immediately after the first
`targetVariantState()` call, invoke the bootstrap and replace the local state
with the verified returned state:

```php
$bootstrap = $this->bootstrapLegacyVariantIdentity(
    $bootstrapPolicy,
    $identityResolver,
    $mirror,
    $target,
    $sourceById,
    $sourceOptions,
    $targetState
);

if ($bootstrap['status'] === 'unsafe') {
    return false;
}

$targetState = $bootstrap['target_state'];
```

- [ ] **Step 4: Implement one-to-one execution and shared postcondition validation**

Add these focused private methods near `syncVariantsStrict()`:

```php
private function bootstrapLegacyVariantIdentity(
    LegacyParentVariantBootstrapPolicy $policy,
    ShopifyParentIdentityResolver $identityResolver,
    ProductMirror $mirror,
    Shop $target,
    array $sourceById,
    array $sourceOptions,
    array $targetState
): array {
    $decision = $policy->decide($targetState, count($sourceById));

    if ($decision['status'] === 'not_needed') {
        return [
            'status' => 'not_needed',
            'target_state' => $targetState,
            'reset_mirrors' => false,
        ];
    }

    if ($decision['status'] === 'unsafe') {
        Log::warning('Variant identity bootstrap skipped: unsafe legacy state', [
            'reason' => $decision['reason'],
            'source_product_id' => $this->sourceProductId,
            'target_shop' => $target->domain,
            'target_product_gid' => $mirror->target_product_gid,
            'source_variant_ids' => array_map('intval', array_keys($sourceById)),
            'managed_parentvariant_ids' => array_keys($targetState['by_parent_id'] ?? []),
            'unmanaged_variant_gids' => $targetState['unmanaged_gids'] ?? [],
            'ambiguous_parentvariant_ids' => array_keys(
                $targetState['ambiguous_parent_ids'] ?? []
            ),
        ]);

        return [
            'status' => 'unsafe',
            'target_state' => $targetState,
            'reset_mirrors' => false,
        ];
    }

    if ($decision['action'] === 'attach_single') {
        $sourceId = (int) array_key_first($sourceById);
        $targetVariantGid = $targetState['unmanaged_gids'][0];

        $this->setParentVariantMetafield(
            $target,
            $targetVariantGid,
            $sourceId,
            'legacy_bootstrap_single'
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

        Log::notice('Variant identity bootstrapped on existing target variant', [
            'source_product_id' => $this->sourceProductId,
            'source_variant_id' => $sourceId,
            'target_shop' => $target->domain,
            'target_variant_gid' => $targetVariantGid,
        ]);

        return [
            'status' => 'bootstrapped',
            'target_state' => $verifiedState,
            'reset_mirrors' => true,
        ];
    }

    // The replace_structure branch is added in Task 3.
    throw new \RuntimeException('Unsupported legacy parentvariant bootstrap action');
}

private function assertBootstrapIdentityState(array $targetState, array $sourceIds): void
{
    $expected = array_map('strval', $sourceIds);
    $actual = array_map('strval', array_keys($targetState['by_parent_id'] ?? []));
    sort($expected, SORT_STRING);
    sort($actual, SORT_STRING);

    if (!empty($targetState['unmanaged_gids'])
        || !empty($targetState['ambiguous_parent_ids'])
        || $expected !== $actual
    ) {
        throw new \RuntimeException('legacy_variant_bootstrap_postcondition_failed');
    }
}

private function replaceVariantMirrorsFromVerifiedState(
    ProductMirror $mirror,
    array $sourceById,
    array $targetState
): void {
    DB::transaction(function () use ($mirror, $sourceById, $targetState): void {
        VariantMirror::where('product_mirror_id', $mirror->id)->delete();

        foreach ($sourceById as $sourceId => $sourceVariant) {
            $targetNode = $targetState['by_parent_id'][(string) $sourceId];
            VariantMirror::create([
                'product_mirror_id' => $mirror->id,
                'source_variant_id' => (int) $sourceId,
                'source_options_key' => $sourceVariant['source_options_key'],
                'target_variant_id' => (int) (
                    $targetNode['legacyResourceId']
                    ?? $this->numericIdFromGid($targetNode['id'])
                    ?? 0
                ),
                'target_variant_gid' => $targetNode['id'],
            ]);
        }
    });
}
```

- [ ] **Step 5: Run focused tests**

Run:

```bash
php artisan test tests/Unit/LegacyParentVariantBootstrapPolicyTest.php tests/Unit/ReplicateProductUpdateIdentityContractTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit one-to-one bootstrap**

```bash
git add app/Jobs/ReplicateProductUpdateToShop.php tests/Unit/ReplicateProductUpdateIdentityContractTest.php
git commit -m "feat: bootstrap single legacy parentvariant on update"
```

