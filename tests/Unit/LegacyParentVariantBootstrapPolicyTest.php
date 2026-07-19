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
