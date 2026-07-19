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

