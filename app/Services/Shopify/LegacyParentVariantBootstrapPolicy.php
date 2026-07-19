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
