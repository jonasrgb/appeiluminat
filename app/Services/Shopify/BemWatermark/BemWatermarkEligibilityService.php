<?php

namespace App\Services\Shopify\BemWatermark;

use App\Models\Shop;

class BemWatermarkEligibilityService
{
    public function isEnabled(): bool
    {
        return (bool) config('features.bem_watermark_sync.enabled', false);
    }

    public function isDryRun(): bool
    {
        return (bool) config('features.bem_watermark_sync.dry_run', true);
    }

    public function isUpdateManifestEnabled(): bool
    {
        return (bool) config('features.bem_watermark_sync.update_manifest_enabled', false);
    }

    public function isEligiblePayloadForTarget(array $payload, Shop $target): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if (!$this->hasRequiredTag($payload)) {
            return false;
        }

        return $this->isEligibleTarget($target);
    }

    public function isEligiblePayloadForSource(array $payload, Shop $source): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if (!$this->hasRequiredTag($payload)) {
            return false;
        }

        $domain = strtolower((string) $source->domain);
        $backupDomain = strtolower((string) config('features.bem_watermark_sync.backup_shop_domain'));

        return $domain !== '' && $domain !== $backupDomain;
    }

    public function hasRequiredTag(array $payload): bool
    {
        $required = strtolower(trim((string) config('features.bem_watermark_sync.required_tag', 'wm_test')));
        if ($required === '') {
            return true;
        }

        return in_array($required, $this->normalizeTags($payload['tags'] ?? []), true);
    }

    public function isEligibleTarget(Shop $target): bool
    {
        $domain = strtolower((string) $target->domain);
        $backupDomain = strtolower((string) config('features.bem_watermark_sync.backup_shop_domain'));

        if ($domain === '' || $domain === $backupDomain) {
            return false;
        }

        $allowed = array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            (array) config('features.bem_watermark_sync.target_shop_domains', [])
        );
        $allowed = array_values(array_filter($allowed));

        return empty($allowed) || in_array($domain, $allowed, true);
    }

    public function targetAlias(Shop $target): string
    {
        $domain = strtolower((string) $target->domain);
        $aliases = (array) config('features.bem_watermark_sync.domain_aliases', []);
        $alias = $aliases[$domain] ?? $domain;

        return $this->slugPart($alias);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }

        if (!is_array($tags)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($tag) => strtolower(trim((string) $tag)),
            $tags
        )));
    }

    private function slugPart(string $value): string
    {
        $slug = str($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->toString();

        return $slug !== '' ? $slug : 'shop';
    }
}
