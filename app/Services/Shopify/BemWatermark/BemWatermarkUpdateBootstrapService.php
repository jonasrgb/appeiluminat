<?php

namespace App\Services\Shopify\BemWatermark;

use App\Models\ProductMirror;
use App\Models\Shop;
use App\Models\ShopConnection;
use App\Services\Shopify\ShopifyParentIdentityResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class BemWatermarkUpdateBootstrapService
{
    public function __construct(
        private readonly BemShopifyGraphqlClient $graphql,
        private readonly BemBackupManifestService $manifestService,
        private readonly BemProductWatermarkMetafieldService $metafieldService,
        private readonly BemImageIdentityService $identity,
        private readonly BemWatermarkEligibilityService $eligibility,
        private readonly BemShopifyStagedUploadService $uploadService,
        private readonly ShopifyParentIdentityResolver $parentIdentityResolver
    ) {
    }

    public function bootstrap(
        Shop $source,
        int $sourceProductId,
        string $sourceProductGid,
        string $title,
        array $sourcePayload
    ): BemWatermarkUpdateBootstrapResult {
        if (!$this->eligibility->isUpdateManifestEnabled()) {
            return BemWatermarkUpdateBootstrapResult::skipped('feature_disabled');
        }

        if (!$this->eligibility->isEligiblePayloadForSource($sourcePayload, $source)) {
            return BemWatermarkUpdateBootstrapResult::skipped('source_payload_not_eligible');
        }

        if ($this->eligibility->isDryRun()) {
            return BemWatermarkUpdateBootstrapResult::skipped('dry_run');
        }

        $backup = $this->backupShop();
        if (!$backup) {
            return BemWatermarkUpdateBootstrapResult::skipped('backup_shop_not_found');
        }

        $sourceState = $this->fetchSourceState($source, $sourceProductGid);
        $sourceImages = $this->normalizeImages($sourceState['images'] ?? []);
        if (empty($sourceImages)) {
            $sourceImages = $this->normalizeImages($sourcePayload['images'] ?? []);
        }
        if (empty($sourceImages)) {
            return BemWatermarkUpdateBootstrapResult::skipped('source_has_no_images');
        }

        $changes = [];

        $backupMirror = $this->ensureMirror(
            source: $source,
            target: $backup,
            sourceProductId: $sourceProductId,
            sourceProductGid: $sourceProductGid,
            role: 'backup'
        );

        if (!$backupMirror || !$backupMirror->target_product_gid) {
            return BemWatermarkUpdateBootstrapResult::skipped('backup_product_not_found', [
                'source_product_id' => $sourceProductId,
            ]);
        }

        $backupState = $this->fetchProductState($backup, $backupMirror->target_product_gid);
        $backupImages = $this->normalizeImages($backupState['images'] ?? []);
        $backupProductHadNoLiveImages = empty($backupImages);
        $sourceWatermarked = $this->decodeJsonMetafield($sourceState['watermarked_value'] ?? null);
        if (is_array($sourceWatermarked) && !$this->sourceWatermarkedBelongsToProduct(
            $sourceWatermarked,
            $sourceProductId,
            $sourceProductGid
        )) {
            Log::warning('BEM update bootstrap ignoring inherited prod.watermarked metafield', [
                'source_shop' => $source->domain,
                'source_product_id' => $sourceProductId,
                'source_product_gid' => $sourceProductGid,
                'metafield_source_product_id' => $sourceWatermarked['source_product_id'] ?? null,
                'metafield_target_product_id' => $sourceWatermarked['target_product_id'] ?? null,
                'metafield_source_product_gid' => $sourceWatermarked['source_product_gid'] ?? null,
                'metafield_target_product_gid' => $sourceWatermarked['target_product_gid'] ?? null,
                'metafield_mode' => $sourceWatermarked['mode'] ?? null,
            ]);

            $sourceWatermarked = null;
            $changes[] = 'inherited_source_watermarked_ignored';
        }

        $hasSourceHistory = is_array($sourceWatermarked)
            && !empty($sourceWatermarked['images'])
            && is_array($sourceWatermarked['images']);

        if (!$hasSourceHistory) {
            if ($this->containsWatermarkedOrMissingImage($sourceImages)) {
                Log::warning('BEM update bootstrap skipped backup seed: source images are not clean', [
                    'source_shop' => $source->domain,
                    'source_product_id' => $sourceProductId,
                    'backup_product_gid' => $backupMirror->target_product_gid,
                ]);

                return BemWatermarkUpdateBootstrapResult::skipped('source_images_not_clean_for_legacy_bootstrap', [
                    'source_product_id' => $sourceProductId,
                    'backup_product_gid' => $backupMirror->target_product_gid,
                ]);
            }

            $backupImages = $this->seedBackupImagesFromCleanSourcePayload(
                backup: $backup,
                backupProductGid: $backupMirror->target_product_gid,
                sourceImages: $sourceImages,
                title: $title
            );
            $changes[] = $backupProductHadNoLiveImages
                ? 'backup_images_seeded_from_source_payload'
                : 'backup_images_reconciled_from_current_source_payload';
        } else {
            if (empty($backupImages)) {
                $backupImages = $this->backupImagesFromSourceHistory($sourceWatermarked);
                if (!empty($backupImages)) {
                    $changes[] = 'backup_images_loaded_from_source_history';
                }
            }

            if ($backupProductHadNoLiveImages && !empty($backupImages)) {
                $backupImages = $this->seedBackupImagesFromHistory(
                    backup: $backup,
                    backupProductGid: $backupMirror->target_product_gid,
                    backupImages: $backupImages,
                    title: $title
                );
                $changes[] = 'backup_images_seeded_from_history';
            }

            if (empty($backupImages)) {
                $backupImages = $this->backupImagesFromMirrorSnapshot($backupMirror);
                if (!empty($backupImages)) {
                    $changes[] = 'backup_images_loaded_from_mirror_snapshot';
                }
            }

            if (empty($backupImages)) {
                if ($this->containsWatermarkedOrMissingImage($sourceImages)) {
                    Log::warning('BEM update bootstrap skipped backup seed: source images are not clean', [
                        'source_shop' => $source->domain,
                        'source_product_id' => $sourceProductId,
                        'backup_product_gid' => $backupMirror->target_product_gid,
                    ]);

                    return BemWatermarkUpdateBootstrapResult::skipped('source_images_not_clean_for_backup_seed', [
                        'source_product_id' => $sourceProductId,
                        'backup_product_gid' => $backupMirror->target_product_gid,
                    ]);
                }

                $backupImages = $this->seedBackupImagesFromCleanSourcePayload(
                    backup: $backup,
                    backupProductGid: $backupMirror->target_product_gid,
                    sourceImages: $sourceImages,
                    title: $title
                );
                $changes[] = 'backup_images_seeded_from_source_payload';
            }
        }

        $this->assertImagesAreClean($backupImages, 'backup');

        if (!$hasSourceHistory) {
            $bootstrapImages = $this->bootstrapImages($sourceImages, $backupImages, $title);

            $this->metafieldService->update($source, $sourceProductGid, [
                'status' => 'completed',
                'mode' => 'bootstrap_from_update',
                'source_shop' => $source->domain,
                'source_product_id' => $sourceProductId,
                'source_product_gid' => $sourceProductGid,
                'target_shop' => $source->domain,
                'target_product_id' => $sourceProductId,
                'target_product_gid' => $sourceProductGid,
                'backup_shop' => $backup->domain,
                'backup_product_id' => $backupMirror->target_product_id,
                'backup_product_gid' => $backupMirror->target_product_gid,
                'updated_at' => now()->toIso8601String(),
                'dry_run' => false,
                'images' => $this->sourceMetafieldImages($bootstrapImages),
            ]);

            $manifest = $this->manifestService->fetch($backup, $backupMirror->target_product_gid);
            if (empty($manifest['images'])) {
                $manifest['images'] = $this->manifestImages($bootstrapImages);
            }
            $manifest = $this->manifestService->appendHistory($manifest, 'bootstrapped_from_update', [
                'source_shop' => $source->domain,
                'source_product_id' => $sourceProductId,
                'source_product_gid' => $sourceProductGid,
                'backup_product_gid' => $backupMirror->target_product_gid,
                'images_count' => count($bootstrapImages),
            ]);
            $this->manifestService->update($backup, $backupMirror->target_product_gid, $manifest);

            $changes[] = 'source_watermarked_initialized';
            $changes[] = 'backup_manifest_initialized';
        }

        if (($backupMirror->wasRecentlyCreated ?? false) === true) {
            $changes[] = 'backup_mirror_created';
        }

        $linkedTargets = $this->linkExistingTargetMirrors($source, $sourceProductId, $sourceProductGid, $backup);
        if ($linkedTargets > 0) {
            $changes[] = 'target_mirrors_created';
        }

        if (empty($changes)) {
            return BemWatermarkUpdateBootstrapResult::noop('already_ready', [
                'source_product_id' => $sourceProductId,
                'backup_product_gid' => $backupMirror->target_product_gid,
            ]);
        }

        Log::info('BEM update bootstrap completed', [
            'source_shop' => $source->domain,
            'source_product_id' => $sourceProductId,
            'changes' => $changes,
            'linked_targets' => $linkedTargets,
        ]);

        return BemWatermarkUpdateBootstrapResult::completed('bootstrapped', [
            'source_product_id' => $sourceProductId,
            'backup_product_gid' => $backupMirror->target_product_gid,
            'changes' => $changes,
            'linked_targets' => $linkedTargets,
        ]);
    }

    private function backupShop(): ?Shop
    {
        $backupDomain = strtolower((string) config('features.bem_watermark_sync.backup_shop_domain'));

        return Shop::whereRaw('LOWER(domain) = ?', [$backupDomain])->first();
    }

    private function ensureMirror(
        Shop $source,
        Shop $target,
        int $sourceProductId,
        string $sourceProductGid,
        string $role
    ): ?ProductMirror {
        $existing = ProductMirror::where([
            'source_shop_id' => $source->id,
            'source_product_id' => $sourceProductId,
            'target_shop_id' => $target->id,
        ])->first();

        $resolution = $this->parentIdentityResolver->resolveProduct(
            $target,
            $sourceProductId,
            $existing?->target_product_gid
        );

        if (
            $resolution['status'] === 'missing'
            && $role === 'backup'
            && $existing?->target_product_gid
            && !$this->targetMirrorIsShared($existing)
        ) {
            try {
                $repaired = $this->parentIdentityResolver->repairMissingParentProduct(
                    $target,
                    $sourceProductId,
                    $existing->target_product_gid
                );

                if ($repaired) {
                    Log::notice('BEM update bootstrap repaired missing backup parentproduct', [
                        'target_shop' => $target->domain,
                        'source_product_id' => $sourceProductId,
                        'target_product_gid' => $existing->target_product_gid,
                    ]);

                    $resolution = $this->parentIdentityResolver->resolveProduct(
                        $target,
                        $sourceProductId,
                        $existing->target_product_gid
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('BEM update bootstrap could not repair missing backup parentproduct', [
                    'target_shop' => $target->domain,
                    'source_product_id' => $sourceProductId,
                    'target_product_gid' => $existing->target_product_gid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($resolution['status'] !== 'found' || empty($resolution['product']['id'])) {
            Log::warning('BEM update bootstrap stopped: strict parentproduct resolution failed', [
                'role' => $role,
                'target_shop' => $target->domain,
                'source_product_id' => $sourceProductId,
                'status' => $resolution['status'],
                'cached_target_product_gid' => $existing?->target_product_gid,
                'candidates' => array_map(static fn (array $candidate): array => [
                    'target_product_gid' => $candidate['id'] ?? null,
                    'target_product_id' => $candidate['legacyResourceId'] ?? null,
                    'target_title' => $candidate['title'] ?? null,
                    'target_handle' => $candidate['handle'] ?? null,
                    'parentproduct' => $candidate['metafield']['value'] ?? null,
                ], $resolution['candidates'] ?? []),
            ]);

            return null;
        }

        $candidate = $resolution['product'];

        $mirror = ProductMirror::updateOrCreate(
            [
                'source_shop_id' => $source->id,
                'source_product_id' => $sourceProductId,
                'target_shop_id' => $target->id,
            ],
            [
                'source_product_gid' => $sourceProductGid,
                'target_product_gid' => $candidate['id'],
                'target_product_id' => (int) ($candidate['legacyResourceId'] ?? $this->legacyIdFromGid($candidate['id'])),
            ]
        );

        Log::notice('BEM update bootstrap: product mirror created from existing Shopify product', [
            'role' => $role,
            'target_shop' => $target->domain,
            'source_product_id' => $sourceProductId,
            'target_product_gid' => $mirror->target_product_gid,
            'handle' => $candidate['handle'] ?? null,
        ]);

        return $mirror;
    }

    private function targetMirrorIsShared(ProductMirror $mirror): bool
    {
        return ProductMirror::query()
            ->where('target_shop_id', $mirror->target_shop_id)
            ->where('target_product_gid', $mirror->target_product_gid)
            ->whereKeyNot($mirror->getKey())
            ->exists();
    }

    private function fetchSourceState(Shop $source, string $sourceProductGid): array
    {
        $query = <<<'GQL'
        query BemBootstrapSourceProduct($id: ID!) {
          product(id: $id) {
            id
            legacyResourceId
            title
            handle
            images(first: 250) {
              nodes {
                id
                url
                altText
              }
            }
            variants(first: 100) {
              nodes { sku }
            }
            metafield(namespace: "prod", key: "watermarked") {
              value
            }
          }
        }
        GQL;

        $response = $this->graphql->request($source, $query, ['id' => $sourceProductGid]);
        $product = $response['data']['product'] ?? null;
        if (!$product) {
            throw new \RuntimeException('BEM update bootstrap source product not found');
        }

        $product['watermarked_value'] = $product['metafield']['value'] ?? null;

        return $product;
    }

    private function fetchProductState(Shop $shop, string $productGid): array
    {
        $query = <<<'GQL'
        query BemBootstrapProductState($id: ID!) {
          product(id: $id) {
            id
            legacyResourceId
            title
            handle
            images(first: 250) {
              nodes {
                id
                url
                altText
              }
            }
          }
        }
        GQL;

        $response = $this->graphql->request($shop, $query, ['id' => $productGid]);
        $product = $response['data']['product'] ?? null;
        if (!$product) {
            throw new \RuntimeException('BEM update bootstrap target product not found on '.$shop->domain);
        }

        return $product;
    }

    private function linkExistingTargetMirrors(
        Shop $source,
        int $sourceProductId,
        string $sourceProductGid,
        Shop $backup
    ): int {
        $targets = ShopConnection::where('source_shop_id', $source->id)
            ->with('target')
            ->get()
            ->pluck('target')
            ->filter(fn ($shop) => $shop instanceof Shop && $shop->is_active)
            ->filter(fn (Shop $shop) => (int) $shop->id !== (int) $backup->id)
            ->filter(fn (Shop $shop) => (int) $shop->id !== (int) $source->id)
            ->filter(fn (Shop $shop) => $this->eligibility->isEligibleTarget($shop))
            ->filter(fn (Shop $shop) => $this->isSupportedWatermarkTarget($shop, $backup));

        $created = 0;

        /** @var Collection<int, Shop> $targets */
        foreach ($targets as $target) {
            $mirror = $this->ensureMirror(
                source: $source,
                target: $target,
                sourceProductId: $sourceProductId,
                sourceProductGid: $sourceProductGid,
                role: 'target'
            );

            if ($mirror && ($mirror->wasRecentlyCreated ?? false) === true) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * @param array<int, array<string, mixed>> $sourceImages
     * @param array<int, array<string, mixed>> $backupImages
     * @return array<int, array<string, mixed>>
     */
    private function bootstrapImages(array $sourceImages, array $backupImages, string $title): array
    {
        $images = [];

        foreach ($sourceImages as $index => $sourceImage) {
            $backupImage = $backupImages[$index] ?? null;
            $originalUrl = $backupImage['url'] ?? null;

            if (!$originalUrl && !$this->identity->isWatermarkedUrl($sourceImage['url'] ?? null)) {
                $originalUrl = $sourceImage['url'] ?? null;
            }

            if (!$originalUrl) {
                throw new \RuntimeException('BEM update bootstrap cannot recover original image at position '.($index + 1));
            }

            if ($this->identity->isWatermarkedUrl($originalUrl)) {
                throw new \RuntimeException('BEM update bootstrap refused watermarked original image at position '.($index + 1));
            }

            $images[] = [
                'position' => $index + 1,
                'source_url' => $originalUrl,
                'watermarked_url' => $sourceImage['url'] ?? null,
                'filename' => $this->identity->filenameFromUrl($sourceImage['url'] ?? null),
                'source_media_gid' => $sourceImage['id'] ?? null,
                'original_extension' => $this->identity->extensionFromUrl($originalUrl),
                'alt' => $sourceImage['alt'] ?? $title,
                'status' => 'completed',
            ];
        }

        return $images;
    }

    private function assertImagesAreClean(array $images, string $role): void
    {
        foreach ($images as $image) {
            if ($this->identity->isWatermarkedUrl($image['url'] ?? null)) {
                throw new \RuntimeException('BEM update bootstrap refused '.$role.' image with watermark: '.($image['url'] ?? 'unknown'));
            }
        }
    }

    private function containsWatermarkedOrMissingImage(array $images): bool
    {
        foreach ($images as $image) {
            $url = $image['url'] ?? null;
            if (!$url || $this->identity->isWatermarkedUrl($url)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $sourceWatermarked
     * @return array<int, array<string, mixed>>
     */
    private function backupImagesFromSourceHistory(array $sourceWatermarked): array
    {
        $images = [];

        foreach (array_values((array) ($sourceWatermarked['images'] ?? [])) as $index => $image) {
            $url = $image['source_url'] ?? null;
            if (!$url || $this->identity->isWatermarkedUrl($url)) {
                continue;
            }

            $images[] = [
                'position' => $index + 1,
                'id' => $image['source_media_gid'] ?? null,
                'url' => $url,
                'alt' => $image['alt'] ?? null,
            ];
        }

        return $images;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function backupImagesFromMirrorSnapshot(ProductMirror $backupMirror): array
    {
        $snapshot = is_array($backupMirror->last_snapshot ?? null)
            ? $backupMirror->last_snapshot
            : (is_string($backupMirror->last_snapshot) ? (json_decode($backupMirror->last_snapshot, true) ?: []) : []);

        $images = [];
        foreach (array_values((array) ($snapshot['images'] ?? [])) as $index => $image) {
            $url = $image['src'] ?? ($image['url'] ?? null);
            if (!$url || $this->identity->isWatermarkedUrl($url)) {
                continue;
            }

            $images[] = [
                'position' => $index + 1,
                'id' => $image['id'] ?? null,
                'url' => $url,
                'alt' => $image['alt'] ?? null,
            ];
        }

        return $images;
    }

    /**
     * @param array<int, array<string, mixed>> $sourceImages
     * @return array<int, array<string, mixed>>
     */
    private function seedBackupImagesFromCleanSourcePayload(
        Shop $backup,
        string $backupProductGid,
        array $sourceImages,
        string $title
    ): array {
        $cleanImages = [];

        foreach ($sourceImages as $index => $image) {
            $url = $image['url'] ?? null;
            if (!$url || $this->identity->isWatermarkedUrl($url)) {
                throw new \RuntimeException('BEM update bootstrap cannot seed backup from watermarked or missing source image at position '.($index + 1));
            }

            $cleanImages[] = [
                'position' => $index + 1,
                'source_url' => $url,
                'original_extension' => $this->identity->extensionFromUrl($url),
                'alt' => $image['alt'] ?? $title,
                'status' => 'completed',
            ];
        }

        $uploaded = $this->uploadService->replaceProductImagesFromUrls($backup, $backupProductGid, $cleanImages);
        $backupImages = [];

        foreach ($uploaded as $index => $image) {
            $url = $image['uploaded_url'] ?? ($image['source_url'] ?? null);
            if (!$url) {
                throw new \RuntimeException('BEM update bootstrap backup seed did not return URL at position '.($index + 1));
            }

            $backupImages[] = [
                'position' => $index + 1,
                'id' => null,
                'url' => $url,
                'alt' => $image['alt'] ?? $title,
            ];
        }

        Log::notice('BEM update bootstrap seeded backup images from clean source payload', [
            'backup_shop' => $backup->domain,
            'backup_product_gid' => $backupProductGid,
            'images_count' => count($backupImages),
        ]);

        return $backupImages;
    }

    /**
     * @param array<int, array<string, mixed>> $backupImages
     * @return array<int, array<string, mixed>>
     */
    private function seedBackupImagesFromHistory(
        Shop $backup,
        string $backupProductGid,
        array $backupImages,
        string $title
    ): array {
        $cleanImages = [];

        foreach ($backupImages as $index => $image) {
            $url = $image['url'] ?? null;
            if (!$url || $this->identity->isWatermarkedUrl($url)) {
                throw new \RuntimeException('BEM update bootstrap cannot seed backup from invalid history image at position '.($index + 1));
            }

            $cleanImages[] = [
                'position' => (int) ($image['position'] ?? ($index + 1)),
                'source_url' => $url,
                'original_extension' => $this->identity->extensionFromUrl($url),
                'alt' => $image['alt'] ?? $title,
                'status' => 'completed',
            ];
        }

        $uploaded = $this->uploadService->replaceProductImagesFromUrls($backup, $backupProductGid, $cleanImages);
        $seededImages = [];

        foreach ($uploaded as $index => $image) {
            $url = $image['uploaded_url'] ?? ($image['source_url'] ?? null);
            if (!$url) {
                throw new \RuntimeException('BEM update bootstrap backup history seed did not return URL at position '.($index + 1));
            }

            $seededImages[] = [
                'position' => (int) ($image['position'] ?? ($index + 1)),
                'id' => null,
                'url' => $url,
                'alt' => $image['alt'] ?? $title,
            ];
        }

        Log::notice('BEM update bootstrap seeded backup images from source history', [
            'backup_shop' => $backup->domain,
            'backup_product_gid' => $backupProductGid,
            'images_count' => count($seededImages),
        ]);

        return $seededImages;
    }

    private function normalizeImages(array $nodes): array
    {
        if (isset($nodes['nodes']) && is_array($nodes['nodes'])) {
            $nodes = $nodes['nodes'];
        }

        $images = [];

        foreach ($nodes as $index => $node) {
            $url = $node['url'] ?? ($node['src'] ?? null);
            if (!$url) {
                continue;
            }

            $images[] = [
                'position' => $index + 1,
                'id' => $node['id'] ?? null,
                'url' => $url,
                'alt' => $node['altText'] ?? ($node['alt'] ?? null),
            ];
        }

        return $images;
    }

    private function sourceMetafieldImages(array $images): array
    {
        return array_values(array_map(static fn (array $image) => [
            'position' => $image['position'] ?? null,
            'source_url' => $image['source_url'] ?? null,
            'watermarked_url' => $image['watermarked_url'] ?? null,
            'filename' => $image['filename'] ?? null,
            'original_extension' => $image['original_extension'] ?? null,
            'status' => $image['status'] ?? 'completed',
        ], $images));
    }

    private function manifestImages(array $images): array
    {
        return array_values(array_map(function (array $image) {
            $sourceUrl = $image['source_url'] ?? null;

            return [
                'image_uuid' => 'bem_'.substr(sha1((string) $this->identity->canonicalUrl($sourceUrl)), 0, 26),
                'status' => 'active',
                'position' => $image['position'] ?? null,
                'source_original_url' => $sourceUrl,
                'backup_url' => $sourceUrl,
                'source_watermarked_url' => $image['watermarked_url'] ?? null,
                'source_watermarked_media_gid' => $image['source_media_gid'] ?? null,
                'source_watermarked_filename' => $image['filename'] ?? null,
                'original_extension' => $image['original_extension'] ?? null,
                'updated_at' => now()->toIso8601String(),
            ];
        }, $images));
    }

    private function isSupportedWatermarkTarget(Shop $target, Shop $backup): bool
    {
        $domain = strtolower((string) $target->domain);
        if ($domain === '' || $domain === strtolower((string) $backup->domain)) {
            return false;
        }

        if ((int) $target->id === 8 || $domain === 'eiluminat-bg.myshopify.com') {
            return false;
        }

        $allowed = array_values(array_filter(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            (array) config('features.bem_watermark_sync.target_shop_domains', [])
        )));

        if (empty($allowed)) {
            $allowed = array_keys((array) config('features.bem_watermark_sync.domain_aliases', []));
            $allowed = array_values(array_filter(
                array_map(static fn ($value) => strtolower(trim((string) $value)), $allowed),
                static fn ($value) => $value !== strtolower((string) config('features.bem_watermark_sync.backup_shop_domain'))
            ));
        }

        return in_array($domain, $allowed, true);
    }

    private function decodeJsonMetafield(mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function sourceWatermarkedBelongsToProduct(array $payload, int $sourceProductId, string $sourceProductGid): bool
    {
        $productIds = array_values(array_filter([
            $payload['source_product_id'] ?? null,
            $payload['target_product_id'] ?? null,
        ], static fn ($value) => $value !== null && $value !== ''));

        $productGids = array_values(array_filter([
            $payload['source_product_gid'] ?? null,
            $payload['target_product_gid'] ?? null,
        ], static fn ($value) => $value !== null && $value !== ''));

        if (empty($productIds) && empty($productGids)) {
            return true;
        }

        return in_array((string) $sourceProductId, array_map('strval', $productIds), true)
            || in_array($sourceProductGid, array_map('strval', $productGids), true);
    }

    private function legacyIdFromGid(?string $gid): ?string
    {
        if (!$gid) {
            return null;
        }

        $pos = strrpos($gid, '/');

        return $pos === false ? null : substr($gid, $pos + 1);
    }
}
