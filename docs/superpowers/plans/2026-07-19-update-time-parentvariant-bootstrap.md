# Update-Time Parentvariant Bootstrap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Repair eligible legacy target variants without `custom.parentvariant` during an ordinary strict product update, once per product, across all full-replication target shops.

**Architecture:** Add a pure policy service that classifies the target identity state without inspecting mutable product data. `ReplicateProductUpdateToShop` executes the approved one-to-one metafield repair or one-time declarative `productSet` replacement before its existing unmanaged-variant guard, verifies Shopify state again, atomically refreshes local mirrors, and then continues the existing strict update path.

**Tech Stack:** PHP 8.1, Laravel 10, Shopify Admin GraphQL API, Eloquent, PHPUnit 10, Laravel HTTP fakes.

## Global Constraints

- Product identity remains exclusively `custom.parentproduct`.
- Variant identity remains exclusively `custom.parentvariant`.
- Never match by SKU, title, handle, option names, option values, position, price, or local mirror data.
- Eligible targets are Lustreled, Powerled, eIluminat Backup, and Iluminat Industrial through their shared full-replication job.
- `eiluminat-bg.myshopify.com` remains on its existing stock-and-images-only workflow.
- Do not modify BEM image processing, manifests, staged uploads, retries, or media reconciliation.
- Do not run a full-store scan or bulk backfill.
- Unsafe identity states must remain fail-closed and make no Shopify write.
- Local `VariantMirror` data may change only after Shopify postconditions are verified.

---

## File Map

- Create `app/Services/Shopify/LegacyParentVariantBootstrapPolicy.php`: pure eligibility and action classification with stable reason codes.
- Create `tests/Unit/LegacyParentVariantBootstrapPolicyTest.php`: exhaustive policy tests with no database or network.
- Modify `app/Jobs/ReplicateProductUpdateToShop.php`: inject policy, execute bootstrap, verify postconditions, refresh mirrors, and continue strict sync.
- Modify `tests/Unit/ReplicateProductUpdateIdentityContractTest.php`: mutation contracts, strict ordering, scope, idempotency guards, and no mutable-field fallback assertions.

### Task 1: Pure Legacy Bootstrap Policy

**Files:**
- Create: `app/Services/Shopify/LegacyParentVariantBootstrapPolicy.php`
- Create: `tests/Unit/LegacyParentVariantBootstrapPolicyTest.php`

**Interfaces:**
- Consumes: `ShopifyParentIdentityResolver::targetVariantState()` result and the normalized source variant count.
- Produces: `LegacyParentVariantBootstrapPolicy::decide(array $targetState, int $sourceVariantCount): array{status:string, action:?string, reason:?string}`.

- [ ] **Step 1: Write the failing policy tests**

```php
<?php

namespace Tests\Unit;

use App\Services\Shopify\LegacyParentVariantBootstrapPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyParentVariantBootstrapPolicyTest extends TestCase
{
    public function test_one_source_and_one_unmanaged_target_attaches_to_existing_variant(): void
    {
        $decision = app(LegacyParentVariantBootstrapPolicy::class)->decide(
            $this->state(['gid://shopify/ProductVariant/10']),
            1
        );

        $this->assertSame('eligible', $decision['status']);
        $this->assertSame('attach_single', $decision['action']);
        $this->assertNull($decision['reason']);
    }

    public function test_multiple_source_variants_and_one_unmanaged_target_replace_structure(): void
    {
        $decision = app(LegacyParentVariantBootstrapPolicy::class)->decide(
            $this->state(['gid://shopify/ProductVariant/10']),
            3
        );

        $this->assertSame('eligible', $decision['status']);
        $this->assertSame('replace_structure', $decision['action']);
    }

    public function test_fully_managed_target_needs_no_bootstrap(): void
    {
        $decision = app(LegacyParentVariantBootstrapPolicy::class)->decide(
            $this->state([], ['501' => ['id' => 'gid://shopify/ProductVariant/10']]),
            1
        );

        $this->assertSame('not_needed', $decision['status']);
        $this->assertNull($decision['action']);
    }

    #[DataProvider('unsafeStates')]
    public function test_unsafe_states_fail_closed(array $state, int $sourceCount, string $reason): void
    {
        $decision = app(LegacyParentVariantBootstrapPolicy::class)->decide($state, $sourceCount);

        $this->assertSame('unsafe', $decision['status']);
        $this->assertNull($decision['action']);
        $this->assertSame($reason, $decision['reason']);
    }

    public static function unsafeStates(): array
    {
        return [
            'invalid source payload' => [
                self::makeState(['gid://shopify/ProductVariant/10']),
                0,
                'legacy_variant_bootstrap_source_payload_invalid',
            ],
            'multiple unmanaged variants' => [
                self::makeState([
                    'gid://shopify/ProductVariant/10',
                    'gid://shopify/ProductVariant/11',
                ]),
                2,
                'legacy_variant_bootstrap_multiple_unmanaged',
            ],
            'mixed identity state' => [
                self::makeState(
                    ['gid://shopify/ProductVariant/10'],
                    ['501' => ['id' => 'gid://shopify/ProductVariant/11']]
                ),
                2,
                'legacy_variant_bootstrap_mixed_identity_state',
            ],
            'ambiguous parentvariant' => [
                self::makeState([], [], [
                    '501' => [
                        ['id' => 'gid://shopify/ProductVariant/10'],
                        ['id' => 'gid://shopify/ProductVariant/11'],
                    ],
                ]),
                1,
                'legacy_variant_bootstrap_ambiguous_parentvariant',
            ],
        ];
    }

    private function state(array $unmanaged, array $managed = [], array $ambiguous = []): array
    {
        return self::makeState($unmanaged, $managed, $ambiguous);
    }

    private static function makeState(
        array $unmanaged,
        array $managed = [],
        array $ambiguous = []
    ): array {
        $nodes = [];
        foreach ($unmanaged as $gid) {
            $nodes[$gid] = ['id' => $gid, 'metafield' => null];
        }
        foreach ($managed as $node) {
            $nodes[$node['id']] = $node;
        }
        foreach ($ambiguous as $matches) {
            foreach ($matches as $node) {
                $nodes[$node['id']] = $node;
            }
        }

        return [
            'nodes_by_gid' => $nodes,
            'by_parent_id' => $managed,
            'ambiguous_parent_ids' => $ambiguous,
            'unmanaged_gids' => $unmanaged,
        ];
    }
}
```

- [ ] **Step 2: Run the policy tests and confirm the expected failure**

Run:

```bash
php artisan test tests/Unit/LegacyParentVariantBootstrapPolicyTest.php
```

Expected: FAIL because `LegacyParentVariantBootstrapPolicy` does not exist.

- [ ] **Step 3: Implement the minimal pure policy**

```php
<?php

namespace App\Services\Shopify;

final class LegacyParentVariantBootstrapPolicy
{
    /**
     * @return array{status:'not_needed'|'eligible'|'unsafe', action:?string, reason:?string}
     */
    public function decide(array $targetState, int $sourceVariantCount): array
    {
        if ($sourceVariantCount < 1) {
            return $this->unsafe('legacy_variant_bootstrap_source_payload_invalid');
        }

        if (!empty($targetState['ambiguous_parent_ids'])) {
            return $this->unsafe('legacy_variant_bootstrap_ambiguous_parentvariant');
        }

        $unmanaged = array_values($targetState['unmanaged_gids'] ?? []);
        $managed = $targetState['by_parent_id'] ?? [];

        if (!$unmanaged) {
            return ['status' => 'not_needed', 'action' => null, 'reason' => null];
        }

        if (count($unmanaged) > 1) {
            return $this->unsafe('legacy_variant_bootstrap_multiple_unmanaged');
        }

        if ($managed || count($targetState['nodes_by_gid'] ?? []) !== 1) {
            return $this->unsafe('legacy_variant_bootstrap_mixed_identity_state');
        }

        return [
            'status' => 'eligible',
            'action' => $sourceVariantCount === 1 ? 'attach_single' : 'replace_structure',
            'reason' => null,
        ];
    }

    /** @return array{status:'unsafe', action:null, reason:string} */
    private function unsafe(string $reason): array
    {
        return ['status' => 'unsafe', 'action' => null, 'reason' => $reason];
    }
}
```

- [ ] **Step 4: Run the policy tests**

Run:

```bash
php artisan test tests/Unit/LegacyParentVariantBootstrapPolicyTest.php
```

Expected: PASS, 4 tests and all provider datasets green.

- [ ] **Step 5: Commit the policy**

```bash
git add app/Services/Shopify/LegacyParentVariantBootstrapPolicy.php tests/Unit/LegacyParentVariantBootstrapPolicyTest.php
git commit -m "feat: classify legacy parentvariant bootstrap states"
```

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

### Task 5: Controlled DRAFT Live Verification

**Files:**
- No repository file changes.
- Shopify test objects only; all products remain DRAFT and unpublished.

**Interfaces:**
- Consumes: deployed queue workers with Tasks 1-4 loaded.
- Produces: live evidence for one-to-one repair, multi-variant replacement, idempotency, shop scope, and zero publications.

- [ ] **Step 1: Restart workers only after automated verification passes**

Run:

```bash
php artisan queue:restart
```

Expected: Laravel reports the queue restart signal was broadcast successfully, and the process supervisor starts workers with the new code.

- [ ] **Step 2: Create one unpublished DRAFT source product for one-to-one bootstrap**

Create a source product named `TEST parentvariant bootstrap 1-1`, status `DRAFT`, with no publications, one option value `Culoare = Rosu`, one variant priced `1.00`, and two non-production test images. Let normal create replication finish, then remove only `custom.parentvariant` from each of the four target variants to reproduce the legacy state. Do not modify `custom.parentproduct`.

Expected before trigger: one uniquely linked target product per full-replication shop, one unmanaged target variant per product, DRAFT status, and zero publications.

- [ ] **Step 3: Trigger and verify one-to-one repair**

Set the existing source update trigger and inspect Shopify plus logs.

Expected on Lustreled, Powerled, Backup, and Industrial:

- the existing target variant GID is unchanged;
- `custom.parentvariant` equals the source variant legacy ID;
- option name/value, SKU, and price equal the source;
- exactly one success log `Variant identity bootstrapped on existing target variant` exists per target;
- product status remains DRAFT and publications remain empty.

Expected on Bulgaria: no parentvariant bootstrap event and no change to its special workflow.

- [ ] **Step 4: Create one unpublished DRAFT source product for multi-variant bootstrap**

Create a source product named `TEST parentvariant bootstrap multi`, status `DRAFT`, with no publications, option `Culoare`, variants `Rosu` priced `1.00` and `Verde` priced `2.00`, and two non-production test images. After normal create replication, deliberately reduce each full-replication target to one `Default Title` variant and remove its `custom.parentvariant`, while preserving product `custom.parentproduct`.

Expected before trigger: every full-replication target has exactly one unmanaged default variant; the source has two valid source variant IDs.

- [ ] **Step 5: Trigger and verify one-time declarative replacement**

Expected on Lustreled, Powerled, Backup, and Industrial:

- exactly two target variants exist;
- each target variant has the correct source `custom.parentvariant`;
- no unmanaged or duplicate parentvariant remains;
- option values and prices equal the source;
- exactly one success log `Variant identity structure bootstrapped declaratively` exists per target;
- product status remains DRAFT and publications remain empty.

- [ ] **Step 6: Re-trigger both products to prove idempotency**

Expected:

- all target variant GIDs remain unchanged from the first successful repair;
- no additional bootstrap success event is emitted;
- ordinary strict update logs complete without `incomplete_parentvariant_mapping`;
- no product is published to Online Store, Shop, Google, Facebook, or another channel.

- [ ] **Step 7: Inspect queue and logs for final health**

Run read-only checks for the two source product IDs in the active daily Laravel log and in `jobs`/`failed_jobs`.

Expected: no failed replication job, no repeating identity retry, no ambiguous mapping, and no BEM behavior change attributable to this feature.

