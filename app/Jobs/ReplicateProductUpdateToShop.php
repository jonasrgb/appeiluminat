<?php

namespace App\Jobs;

use App\Models\ProductMirror;
use App\Models\VariantMirror;
use App\Models\Shop;
use App\Models\SourceProductDeletion;
use App\Services\Shopify\LegacyParentVariantBootstrapPolicy;
use App\Services\Shopify\ShopifyParentIdentityResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Shopify\BemWatermark\BemWatermarkEligibilityService;

class ReplicateProductUpdateToShop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = [10, 30, 60, 120];
    public $timeout = 840;
    public $failOnTimeout = true;

    private const META_NAMESPACE = 'custom';

    /** @var array<int, string> */
    private const META_KEYS = [
        'forma',
        'tip_camera',
        'dimensiunea_camerei',
        'model',
        'culoare_carcasa_finisaj',
        'tip_de_lumina',
        'functionalitati',
        'tip_rama',
        'dimensiune',
        'tip',
        'fasung'
    ];

    /** @var array<string, array|null> */
    private array $targetMetaobjectCache = [];

    public function __construct(
        public int $targetShopId,
        public int $sourceShopId,
        public int $sourceProductId,
        public array $payload
    ) {}

    public function middleware(): array
    {
        $key = implode(':', [
            'product-replication-update',
            $this->sourceShopId,
            $this->sourceProductId,
            $this->targetShopId,
        ]);

        return [
            (new WithoutOverlapping($key))
                ->releaseAfter(30)
                ->expireAfter(900),
        ];
    }

public function handle(
    ShopifyParentIdentityResolver $identityResolver,
    LegacyParentVariantBootstrapPolicy $bootstrapPolicy
): void
{
    if (SourceProductDeletion::existsFor($this->sourceShopId, $this->sourceProductId)) {
        Log::warning('ReplicateProductUpdate skipped: source product was deleted', [
            'source_shop_id' => $this->sourceShopId,
            'source_product_id' => $this->sourceProductId,
            'target_shop_id' => $this->targetShopId,
        ]);
        return;
    }

    $target = Shop::findOrFail($this->targetShopId);
    if ((int)$target->id === 8 || $target->domain === 'eiluminat-bg.myshopify.com') {
        Log::info('ReplicateProductUpdateToShop skipped for BG store', [
            'target_shop' => $target->domain,
            'source_shop' => $this->sourceShopId,
            'source_product_id' => $this->sourceProductId,
        ]);
        return;
    }

    $metaDescription = $this->fetchMetaDescriptionFromSource();
    $skipDirectImageSyncForBem = $this->shouldSkipDirectImageSyncForBem();

    $mirror = ProductMirror::where([
        'source_shop_id'    => $this->sourceShopId,
        'target_shop_id'    => $this->targetShopId,
        'source_product_id' => $this->sourceProductId,
    ])->first();

    $productResolution = $identityResolver->resolveProduct(
        $target,
        $this->sourceProductId,
        $mirror?->target_product_gid
    );

    if ($productResolution['status'] !== 'found') {
        Log::warning('ReplicateProductUpdate skipped: strict parentproduct mapping unavailable', [
            'reason' => $productResolution['status'] === 'ambiguous'
                ? 'ambiguous_parentproduct_mapping'
                : 'missing_parentproduct_mapping',
            'source_shop_id' => $this->sourceShopId,
            'source_product_id' => $this->sourceProductId,
            'target_shop_id' => $target->id,
            'target_shop' => $target->domain,
            'candidate_gids' => array_values(array_filter(array_map(
                static fn (array $candidate): ?string => $candidate['id'] ?? null,
                $productResolution['candidates'] ?? []
            ))),
        ]);

        return;
    }

    $resolvedProduct = $productResolution['product'];
    $mirror = ProductMirror::updateOrCreate(
        [
            'source_shop_id' => $this->sourceShopId,
            'target_shop_id' => $this->targetShopId,
            'source_product_id' => $this->sourceProductId,
        ],
        [
            'source_product_gid' => 'gid://shopify/Product/'.$this->sourceProductId,
            'target_product_gid' => $resolvedProduct['id'],
            'target_product_id' => (int) (
                $resolvedProduct['legacyResourceId']
                ?? $this->numericIdFromGid($resolvedProduct['id'])
                ?? 0
            ),
        ]
    );

    $source = Shop::find($this->sourceShopId);
    if (!$this->syncVariantsStrict(
        $identityResolver,
        $bootstrapPolicy,
        $mirror,
        $target,
        $source
    )) {
        return;
    }

    $updateSucceeded = false;
    // Log::info('Replicate update start', [
    //     'target_shop' => $target->domain,
    //     'target_gid'  => $mirror->target_product_gid,
    //     'source_pid'  => $this->sourceProductId,
    // ]);

    // === 1) Core product diff & patch ===
    $lastSnap = $mirror->last_snapshot;
    if (is_string($lastSnap)) {
        $lastSnap = json_decode($lastSnap, true) ?: [];
    } elseif (!is_array($lastSnap)) {
        $lastSnap = [];
    }

    try {
    $productDiff = $this->computeProductDiff($this->payload);
        if (!empty($productDiff)) {
            $this->productUpdate($target, $mirror->target_product_gid, $productDiff);
            Log::info('Product core updated', ['fields' => array_keys($productDiff)]);
        } else {
            Log::info('Product core no-op (no changes)');
        }

        // === 1.1) Options diff (raport) ===
        $srcOptions = $this->normalizeOptionsFromPayload($this->payload);
        $currOptFp  = $this->optionsFingerprint($srcOptions);
        $prevOptFp  = $lastSnap['options_fingerprint'] ?? null;

        if ($currOptFp !== $prevOptFp) {
            // Log::info('Options changed → syncing', [
            //     'target_shop' => $target->domain,
            //     'names'       => array_map(fn($o) => $o['name'], $srcOptions),
            // ]);
            // lăsăm doar raport aici; crearea efectivă doar dacă target are "Default Title"
        } else {
            Log::info('Options unchanged, skipping', ['target_shop' => $target->domain]);
        }

        // === 2) Images sync (always replace) ===
        if ($skipDirectImageSyncForBem) {
            Log::warning('BEM update image sync skipped: source payload images may be watermarked', [
                'target_shop' => $target->domain,
                'source_shop_id' => $this->sourceShopId,
                'source_product_id' => $this->sourceProductId,
                'target_product_gid' => $mirror->target_product_gid,
            ]);
        } else {
            $srcImages = $this->extractSourceImages($this->payload);
            // Log::info('Images syncing (force replace)', [
            //     'target_shop' => $target->domain,
            //     'count'       => count($srcImages),
            // ]);
            $this->syncImagesReplaceAll($target, $mirror->target_product_gid, $srcImages);
            // Log::info('Images synced', [
            //     'target_shop' => $target->domain,
            //     'count'       => count($srcImages),
            // ]);
        }

        if ($mirror->target_product_gid) {
            $this->syncMetaobjectMetafields(
                source: $source,
                target: $target,
                sourceProductGid: 'gid://shopify/Product/' . $this->sourceProductId,
                targetProductGid: $mirror->target_product_gid,
            );
        }

        if ($metaDescription !== null && $metaDescription !== '') {
            $this->applyMetaDescriptionToTarget($target, $mirror->target_product_gid, $metaDescription);
        }

        $updateSucceeded = true;

        // Log::info('Replicate update done', [
        //     'target_shop' => $target->domain,
        //     'target_gid'  => $mirror->target_product_gid,
        // ]);
    } finally {
        if ($updateSucceeded) {
            $newSnap = $this->normalizeProductSnapshot($this->payload);
            if ($skipDirectImageSyncForBem) {
                $previousSnapshot = $mirror->last_snapshot ?? [];
                $previousImages = $previousSnapshot['images'] ?? [];
                $newSnap['images'] = $previousImages;
                $newSnap['images_fingerprint'] = $this->fingerprintImages($previousImages);

                foreach (['bem_update_media_synced_at', 'bem_repaired_at'] as $preservedKey) {
                    if (array_key_exists($preservedKey, $previousSnapshot)) {
                        $newSnap[$preservedKey] = $previousSnapshot[$preservedKey];
                    }
                }
            }
            $mirror->last_snapshot = $newSnap;
            $mirror->save();
        }
    }
}




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

    private function syncVariantsStrict(
        ShopifyParentIdentityResolver $identityResolver,
        LegacyParentVariantBootstrapPolicy $bootstrapPolicy,
        ProductMirror $mirror,
        Shop $target,
        ?Shop $source
    ): bool {
        if (!$this->sourceVariantPayloadIsComplete($this->payload)) {
            Log::warning('Variant sync stopped: Shopify webhook variant list may be truncated', [
                'reason' => 'source_variant_payload_limit',
                'source_product_id' => $this->sourceProductId,
                'target_shop' => $target->domain,
                'payload_variant_count' => count($this->payload['variants'] ?? []),
            ]);

            return false;
        }

        $sourceOptions = $this->normalizeOptionsFromPayload($this->payload);
        $sourceVariants = $this->normalizeSourceVariants($this->payload, $sourceOptions);
        $sourceById = [];

        if (empty($sourceVariants)) {
            Log::warning('Variant sync stopped: source payload contains no variants', [
                'reason' => 'empty_source_variants',
                'source_product_id' => $this->sourceProductId,
                'target_shop' => $target->domain,
            ]);

            return false;
        }

        foreach ($sourceVariants as $sourceVariant) {
            $optionsKey = (string) ($sourceVariant['source_options_key'] ?? '');
            $sourceId = (int) ($sourceVariant['source_variant_id'] ?? 0);
            if ($sourceId <= 0) {
                Log::warning('Variant sync stopped: source variant has no stable ID', [
                    'reason' => 'missing_source_variant_id',
                    'source_product_id' => $this->sourceProductId,
                    'target_shop' => $target->domain,
                    'options_key' => $optionsKey,
                ]);
                return false;
            }

            if (isset($sourceById[(string) $sourceId])) {
                Log::warning('Variant sync stopped: duplicate source variant ID', [
                    'reason' => 'duplicate_source_variant_id',
                    'source_product_id' => $this->sourceProductId,
                    'source_variant_id' => $sourceId,
                    'target_shop' => $target->domain,
                ]);
                return false;
            }

            $sourceById[(string) $sourceId] = $sourceVariant;
        }

        $targetState = $identityResolver->targetVariantState($target, $mirror->target_product_gid);
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
        $allowStructuralChanges = empty($targetState['unmanaged_gids'])
            && empty($targetState['ambiguous_parent_ids']);

        if (!$allowStructuralChanges) {
            Log::warning('Variant structural sync skipped: target contains unmanaged or ambiguous variants', [
                'reason' => 'incomplete_parentvariant_mapping',
                'source_product_id' => $this->sourceProductId,
                'target_shop' => $target->domain,
                'unmanaged_variant_gids' => $targetState['unmanaged_gids'],
                'ambiguous_parentvariant_ids' => array_keys($targetState['ambiguous_parent_ids']),
            ]);

            return false;
        }

        $verifiedMirrors = $this->reconcileStrictVariantMirrors(
            $mirror,
            $target,
            $sourceById,
            $targetState
        );

        $missing = [];
        foreach ($sourceById as $sourceId => $sourceVariant) {
            if (!isset($verifiedMirrors[$sourceId])) {
                $missing[$sourceId] = $sourceVariant;
            }
        }

        $stale = [];
        foreach ($targetState['by_parent_id'] as $sourceId => $targetNode) {
            if (!isset($sourceById[(string) $sourceId])) {
                $stale[(string) $sourceId] = $targetNode;
            }
        }

        if ($missing && $stale) {
            $targetOptions = $this->fetchTargetOptions($target, $mirror->target_product_gid);
            if (!$this->mixedReplacementHasCompatibleOptions($sourceOptions, $targetOptions)) {
                Log::warning('Variant sync stopped: mixed replacement option structure differs', [
                    'reason' => 'mixed_variant_replacement_option_structure_mismatch',
                    'source_product_id' => $this->sourceProductId,
                    'target_shop' => $target->domain,
                    'source_option_names' => array_column($sourceOptions, 'name'),
                    'target_option_names' => array_column($targetOptions, 'name'),
                    'missing_source_variant_ids' => array_map('intval', array_keys($missing)),
                    'stale_source_variant_ids' => array_map('intval', array_keys($stale)),
                ]);

                $replaced = $this->productSetVariantStructure(
                    $target,
                    $mirror->target_product_gid,
                    $sourceById,
                    $sourceOptions,
                    $targetOptions,
                    $verifiedMirrors
                );

                foreach ($sourceById as $sourceId => $sourceVariant) {
                    $targetVariant = $replaced[$sourceId];
                    VariantMirror::updateOrCreate(
                        [
                            'product_mirror_id' => $mirror->id,
                            'source_variant_id' => (int) $sourceId,
                        ],
                        [
                            'source_options_key' => $sourceVariant['source_options_key'],
                            'target_variant_id' => (int) ($targetVariant['variant_id'] ?? 0),
                            'target_variant_gid' => $targetVariant['variant_gid'],
                        ]
                    );
                }

                VariantMirror::where('product_mirror_id', $mirror->id)
                    ->whereNotIn('source_variant_id', array_map('intval', array_keys($sourceById)))
                    ->delete();

                $targetState = $identityResolver->targetVariantState(
                    $target,
                    $mirror->target_product_gid
                );
                $expectedParentIds = array_map('strval', array_keys($sourceById));
                $actualParentIds = array_map('strval', array_keys($targetState['by_parent_id']));
                sort($expectedParentIds, SORT_STRING);
                sort($actualParentIds, SORT_STRING);

                if (!empty($targetState['unmanaged_gids'])
                    || !empty($targetState['ambiguous_parent_ids'])
                    || $actualParentIds !== $expectedParentIds
                ) {
                    throw new \RuntimeException(
                        'Variant identity state is incomplete after productSet structural replacement'
                    );
                }

                $verifiedMirrors = $this->reconcileStrictVariantMirrors(
                    $mirror,
                    $target,
                    $sourceById,
                    $targetState
                );
                $missing = [];
                $stale = [];

                Log::notice('Variant structure replaced declaratively through parentvariant', [
                    'source_product_id' => $this->sourceProductId,
                    'target_shop' => $target->domain,
                    'source_variant_ids' => array_map('intval', array_keys($sourceById)),
                ]);
            }
        }

        if ($missing) {
                // New variants need their option definitions before Shopify can
                // accept optionValues in productVariantsBulkCreate.
                $this->syncStrictProductOptions($target, $mirror->target_product_gid);
                $targetState = $identityResolver->targetVariantState($target, $mirror->target_product_gid);

                $created = $this->productVariantsBulkCreateForUpdate(
                    $target,
                    $mirror->target_product_gid,
                    $missing,
                    $sourceOptions,
                    !empty($targetState['nodes_by_gid'])
                );

                foreach ($missing as $sourceVariant) {
                    $sourceId = (int) $sourceVariant['source_variant_id'];
                    $createdVariant = $created[(string) $sourceId] ?? null;
                    $targetGid = $createdVariant['variant_gid'] ?? null;

                    if (!$targetGid) {
                        throw new \RuntimeException(
                            'Variant create did not return a deterministic target ID for source variant '.$sourceId
                        );
                    }

                    $this->setParentVariantMetafield(
                        $target,
                        $targetGid,
                        $sourceId,
                        'deterministic_create'
                    );

                    $verifiedMirrors[(string) $sourceId] = VariantMirror::updateOrCreate(
                        [
                            'product_mirror_id' => $mirror->id,
                            'source_variant_id' => $sourceId,
                        ],
                        [
                            'source_options_key' => $sourceVariant['source_options_key'],
                            'target_variant_id' => (int) (
                                $createdVariant['variant_id']
                                ?? $this->numericIdFromGid($targetGid)
                                ?? 0
                            ),
                            'target_variant_gid' => $targetGid,
                        ]
                    );
                }
        }

        $staleVariantGids = array_values(array_filter(array_map(
            static fn (array $targetNode) => $targetNode['id'] ?? null,
            $stale
        )));

        if ($staleVariantGids) {
            $this->productVariantsBulkDelete(
                $target,
                $mirror->target_product_gid,
                $staleVariantGids
            );

            foreach ($stale as $sourceId => $targetNode) {
                VariantMirror::where('product_mirror_id', $mirror->id)
                    ->where('source_variant_id', (int) $sourceId)
                    ->delete();

                Log::info('Variant deleted on target through parentvariant', [
                    'target_shop' => $target->domain,
                    'source_variant_id' => (int) $sourceId,
                    'target_variant_gid' => $targetNode['id'] ?? null,
                ]);
            }
        }

        // Collapse/rename options only after stale variants are gone. Shopify
        // refuses deleting an option value while a variant still uses it.
        $this->syncStrictProductOptions($target, $mirror->target_product_gid);
        $targetState = $identityResolver->targetVariantState($target, $mirror->target_product_gid);
        if (!empty($targetState['unmanaged_gids']) || !empty($targetState['ambiguous_parent_ids'])) {
            Log::warning('Variant sync stopped after option synchronization changed identity state', [
                'reason' => 'incomplete_parentvariant_mapping_after_option_sync',
                'source_product_id' => $this->sourceProductId,
                'target_shop' => $target->domain,
                'unmanaged_variant_gids' => $targetState['unmanaged_gids'],
                'ambiguous_parentvariant_ids' => array_keys($targetState['ambiguous_parent_ids']),
            ]);

            return false;
        }

        $verifiedMirrors = $this->reconcileStrictVariantMirrors(
            $mirror,
            $target,
            $sourceById,
            $targetState
        );

        $bulkUpdates = [];
        foreach ($sourceById as $sourceId => $sourceVariant) {
            $variantMirror = $verifiedMirrors[$sourceId] ?? null;
            if (!$variantMirror?->target_variant_gid) {
                continue;
            }

            $bulkUpdates[] = $this->buildVariantUpdateInput(
                $variantMirror->target_variant_gid,
                $sourceVariant
            );
        }

        if ($bulkUpdates) {
            $this->productVariantsBulkUpdate($target, $mirror->target_product_gid, $bulkUpdates);
        }

        $sourceTrackedById = [];
        if ($source) {
            try {
                $sourceTrackedById = $this->fetchTrackedMapBySourceId(
                    $source,
                    'gid://shopify/Product/'.$this->sourceProductId
                );
            } catch (\Throwable $e) {
                Log::warning('Source tracked fetch failed; using payload fallback', [
                    'source_shop' => $source->domain,
                    'source_product_id' => $this->sourceProductId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($sourceById as $sourceId => $sourceVariant) {
            $variantMirror = $verifiedMirrors[$sourceId] ?? null;
            if (!$variantMirror?->target_variant_gid) {
                continue;
            }

            $tracked = $sourceTrackedById[$sourceId] ?? null;
            if ($tracked === null && ($sourceVariant['inventory_management_present'] ?? false)) {
                $tracked = strtolower((string) ($sourceVariant['inventory_management'] ?? '')) === 'shopify';
            }

            [$inventoryItemId, $locationIds] = $this->fetchInventoryItemAndLocations(
                $target,
                $variantMirror->target_variant_gid
            );

            if ($tracked !== null && $inventoryItemId) {
                $this->inventoryItemUpdate($target, $inventoryItemId, (bool) $tracked);
            }

            if ($tracked !== false
                && $inventoryItemId
                && array_key_exists('inventory_quantity', $sourceVariant)
                && $sourceVariant['inventory_quantity'] !== null
            ) {
                $this->inventorySetQuantities(
                    $target,
                    $inventoryItemId,
                    $locationIds,
                    (int) $sourceVariant['inventory_quantity']
                );
            }

            $variantMirror->source_options_key = $sourceVariant['source_options_key'];
            $variantMirror->variant_fingerprint = $sourceVariant['variant_fingerprint'] ?? null;
            $variantMirror->inventory_fingerprint = $sourceVariant['inventory_fingerprint'] ?? null;
            $variantMirror->save();
        }

        return true;
    }

    private function sourceVariantPayloadIsComplete(array $payload): bool
    {
        $variants = $payload['variants'] ?? [];

        return is_array($variants) && count($variants) < 100;
    }

    private function mixedReplacementHasCompatibleOptions(
        array $sourceOptions,
        array $targetOptions
    ): bool {
        $sourceNames = array_map(
            fn (array $option): string => $this->canonOptName((string) ($option['name'] ?? '')),
            array_values($sourceOptions)
        );
        $targetNames = array_map(
            fn (array $option): string => $this->canonOptName((string) ($option['name'] ?? '')),
            array_values($targetOptions)
        );

        if (!$sourceNames
            || in_array('', $sourceNames, true)
            || in_array('', $targetNames, true)
            || count(array_unique($sourceNames)) !== count($sourceNames)
            || count(array_unique($targetNames)) !== count($targetNames)
        ) {
            return false;
        }

        return $sourceNames === $targetNames;
    }

    /**
     * Replace a changed option/variant structure in one synchronous mutation.
     * Existing variants are addressed only by GIDs verified through parentvariant.
     *
     * @return array<string, array{source_variant_id:int, variant_id:int|string|null, variant_gid:string}>
     */
    private function productSetVariantStructure(
        Shop $shop,
        string $productGid,
        array $sourceById,
        array $sourceOptions,
        array $targetOptions,
        array $verifiedMirrors,
        bool $allowCompatibleOptionStructure = false
    ): array {
        if (!$sourceById || !$sourceOptions) {
            throw new \RuntimeException('productSet structural replacement requires variants and options');
        }

        if (!$allowCompatibleOptionStructure
            && $this->mixedReplacementHasCompatibleOptions($sourceOptions, $targetOptions)
        ) {
            throw new \RuntimeException('productSet structural replacement was called for compatible options');
        }

        $productOptions = [];
        foreach (array_values($sourceOptions) as $position => $option) {
            $name = trim((string) ($option['name'] ?? ''));
            $values = array_values(array_unique(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                $option['values'] ?? []
            ), static fn (string $value): bool => $value !== '')));

            if ($name === '' || !$values) {
                throw new \RuntimeException('productSet structural replacement received an incomplete option');
            }

            $productOptions[] = [
                'name' => $name,
                'position' => $position + 1,
                'values' => array_map(
                    static fn (string $value): array => ['name' => $value],
                    $values
                ),
            ];
        }

        $variantInputs = [];
        foreach ($sourceById as $sourceIdKey => $sourceVariant) {
            $sourceId = (int) ($sourceVariant['source_variant_id'] ?? 0);
            if ($sourceId <= 0 || (string) $sourceId !== (string) $sourceIdKey) {
                throw new \RuntimeException('productSet structural replacement has an invalid source variant ID');
            }

            $optionValues = $this->buildOptionValuesForProductSet(
                (string) ($sourceVariant['source_options_key'] ?? ''),
                $sourceOptions
            );
            if (count($optionValues) !== count($productOptions)) {
                throw new \RuntimeException(
                    'productSet structural replacement has incomplete variant option values for source '.$sourceId
                );
            }

            $variantInput = [
                'optionValues' => $optionValues,
            ];
            $retainedMirror = $verifiedMirrors[(string) $sourceId] ?? null;
            if ($retainedMirror instanceof VariantMirror && $retainedMirror->target_variant_gid) {
                $variantInput['id'] = $retainedMirror->target_variant_gid;
            } else {
                $variantInput['metafields'] = [[
                    'namespace' => 'custom',
                    'key' => 'parentvariant',
                    'type' => 'number_integer',
                    'value' => (string) $sourceId,
                ]];
            }

            if (array_key_exists('price', $sourceVariant)
                && $sourceVariant['price'] !== null
                && $sourceVariant['price'] !== ''
            ) {
                $variantInput['price'] = (string) $sourceVariant['price'];
            }
            if (array_key_exists('compare_at_price', $sourceVariant)) {
                $compareAtPrice = $sourceVariant['compare_at_price'];
                $variantInput['compareAtPrice'] = ($compareAtPrice === null || $compareAtPrice === '')
                    ? null
                    : (string) $compareAtPrice;
            }
            if (array_key_exists('taxable', $sourceVariant) && $sourceVariant['taxable'] !== null) {
                $variantInput['taxable'] = (bool) $sourceVariant['taxable'];
            }
            if (!empty($sourceVariant['inventory_policy'])) {
                $variantInput['inventoryPolicy'] = strtolower((string) $sourceVariant['inventory_policy']) === 'continue'
                    ? 'CONTINUE'
                    : 'DENY';
            }
            if (array_key_exists('sku', $sourceVariant)) {
                $variantInput['sku'] = (string) ($sourceVariant['sku'] ?? '');
            }
            if (array_key_exists('barcode', $sourceVariant)) {
                $variantInput['barcode'] = ($sourceVariant['barcode'] === null || $sourceVariant['barcode'] === '')
                    ? null
                    : (string) $sourceVariant['barcode'];
            }

            $variantInputs[] = $variantInput;
        }

        $mutation = <<<'GQL'
        mutation SyncProductVariantStructure(
          $identifier: ProductSetIdentifiers!
          $input: ProductSetInput!
          $synchronous: Boolean!
        ) {
          productSet(identifier: $identifier, input: $input, synchronous: $synchronous) {
            product {
              id
              variants(first: 250) {
                nodes {
                  id
                  legacyResourceId
                  metafield(namespace: "custom", key: "parentvariant") { value }
                }
              }
            }
            userErrors { field message code }
          }
        }
        GQL;

        $response = $this->gql($shop, $mutation, [
            'identifier' => ['id' => $productGid],
            'input' => [
                'productOptions' => $productOptions,
                'variants' => $variantInputs,
            ],
            'synchronous' => true,
        ]);

        if (!empty($response['errors'])) {
            throw new \RuntimeException('productSet structural replacement errors: '.json_encode($response['errors']));
        }
        $userErrors = $response['data']['productSet']['userErrors'] ?? [];
        if ($userErrors) {
            throw new \RuntimeException('productSet structural replacement userErrors: '.json_encode($userErrors));
        }

        $product = $response['data']['productSet']['product'] ?? null;
        if (($product['id'] ?? null) !== $productGid) {
            throw new \RuntimeException('productSet structural replacement did not return the requested product');
        }

        $result = [];
        foreach ($product['variants']['nodes'] ?? [] as $node) {
            $sourceId = (string) ($node['metafield']['value'] ?? '');
            $variantGid = $node['id'] ?? null;
            if (!isset($sourceById[$sourceId]) || isset($result[$sourceId]) || !$variantGid) {
                throw new \RuntimeException('productSet returned an invalid parentvariant identity state');
            }

            $result[$sourceId] = [
                'source_variant_id' => (int) $sourceId,
                'variant_id' => $node['legacyResourceId']
                    ?? $this->numericIdFromGid($variantGid),
                'variant_gid' => $variantGid,
            ];
        }

        $expectedIds = array_map('strval', array_keys($sourceById));
        $actualIds = array_map('strval', array_keys($result));
        sort($expectedIds, SORT_STRING);
        sort($actualIds, SORT_STRING);
        if ($actualIds !== $expectedIds) {
            throw new \RuntimeException('productSet did not return every expected parentvariant identity');
        }

        return $result;
    }

    private function syncStrictProductOptions(Shop $target, string $productGid): void
    {
        $desiredOptions = $this->buildOptionCreateInputsFromPayload($this->payload);
        $targetOptions = $this->fetchTargetOptions($target, $productGid);
        $hasOnlyDefaultTitle = count($targetOptions) === 1
            && (
                strtolower((string) ($targetOptions[0]['name'] ?? '')) === 'title'
                && array_map('strval', $targetOptions[0]['values'] ?? []) === ['Default Title']
            );

        if (!$desiredOptions) {
            if ($hasOnlyDefaultTitle) {
                return;
            }

            $this->collapseTargetOptionsToDefaultTitle($target, $productGid, $targetOptions);
            return;
        }

        if ($hasOnlyDefaultTitle) {
            $this->productOptionsCreate($target, $productGid, $desiredOptions, 'LEAVE_AS_IS');
            return;
        }

        $desiredNames = array_map(
            fn (array $option): string => $this->canonOptName((string) ($option['name'] ?? '')),
            $desiredOptions
        );
        $targetNames = array_map(
            fn (array $option): string => $this->canonOptName((string) ($option['name'] ?? '')),
            $targetOptions
        );

        if ($targetNames === $desiredNames) {
            return;
        }

        if (count($targetNames) > count($desiredNames)
            && count(array_unique($targetNames)) === count($targetNames)
            && count(array_unique($desiredNames)) === count($desiredNames)
        ) {
            $extraOptions = array_values(array_filter(
                $targetOptions,
                fn (array $option): bool => !in_array(
                    $this->canonOptName((string) ($option['name'] ?? '')),
                    $desiredNames,
                    true
                )
            ));
            $retainedNames = array_values(array_filter(
                $targetNames,
                fn (string $name): bool => in_array($name, $desiredNames, true)
            ));
            $extraOptionIds = array_values(array_filter(array_map(
                static fn (array $option): ?string => $option['id'] ?? null,
                $extraOptions
            )));

            if ($retainedNames === $desiredNames
                && count($extraOptionIds) === count($extraOptions)
                && count($extraOptionIds) === count($targetNames) - count($desiredNames)
            ) {
                $this->productOptionsDelete($target, $productGid, $extraOptionIds);
                return;
            }
        }

        Log::warning('Product strict option sync skipped: non-default option replacement is not deterministic', [
            'target_shop' => $target->domain,
            'product_gid' => $productGid,
        ]);
    }

    private function collapseTargetOptionsToDefaultTitle(
        Shop $target,
        string $productGid,
        array $targetOptions
    ): void {
        if (count($targetOptions) !== 1) {
            throw new \RuntimeException('Default Title collapse requires exactly one target option');
        }

        $option = $targetOptions[0];
        $optionId = $option['id'] ?? null;
        $optionValues = array_values($option['optionValues'] ?? []);
        $activeValues = array_values(array_filter(
            $optionValues,
            static fn (array $value) => (bool) ($value['hasVariants'] ?? false)
        ));

        if (!$optionId || count($activeValues) !== 1 || empty($activeValues[0]['id'])) {
            throw new \RuntimeException('Default Title collapse could not identify the retained option value');
        }

        $retainedValueId = $activeValues[0]['id'];
        $valueIdsToDelete = array_values(array_filter(array_map(
            static fn (array $value) => ($value['id'] ?? null) !== $retainedValueId
                ? ($value['id'] ?? null)
                : null,
            $optionValues
        )));

        $this->productOptionUpdate(
            $target,
            $productGid,
            ['id' => $optionId, 'name' => 'Title', 'position' => 1],
            [['id' => $retainedValueId, 'name' => 'Default Title']],
            $valueIdsToDelete
        );
    }

    /** @return array<string, VariantMirror> */
    private function reconcileStrictVariantMirrors(
        ProductMirror $mirror,
        Shop $target,
        array $sourceById,
        array $targetState
    ): array {
        $verified = [];

        foreach ($sourceById as $sourceId => $sourceVariant) {
            if (isset($targetState['ambiguous_parent_ids'][$sourceId])) {
                Log::warning('Variant update skipped: ambiguous parentvariant mapping', [
                    'reason' => 'ambiguous_parentvariant_mapping',
                    'source_product_id' => $this->sourceProductId,
                    'source_variant_id' => (int) $sourceId,
                    'target_shop' => $target->domain,
                    'candidate_gids' => array_column(
                        $targetState['ambiguous_parent_ids'][$sourceId],
                        'id'
                    ),
                ]);
                continue;
            }

            $targetNode = $targetState['by_parent_id'][$sourceId] ?? null;
            if (!$targetNode) {
                Log::warning('Variant update skipped: strict parentvariant mapping unavailable', [
                    'reason' => 'missing_parentvariant_mapping',
                    'source_product_id' => $this->sourceProductId,
                    'source_variant_id' => (int) $sourceId,
                    'target_shop' => $target->domain,
                ]);
                continue;
            }

            $verified[$sourceId] = VariantMirror::updateOrCreate(
                [
                    'product_mirror_id' => $mirror->id,
                    'source_variant_id' => (int) $sourceId,
                ],
                [
                    'source_options_key' => $sourceVariant['source_options_key'],
                    'target_variant_id' => (int) (
                        $targetNode['legacyResourceId']
                        ?? $this->numericIdFromGid($targetNode['id'])
                        ?? 0
                    ),
                    'target_variant_gid' => $targetNode['id'],
                ]
            );
        }

        return $verified;
    }

    private function gql(Shop $shop, string $query, array $variables = []): array
    {
        $version  = $shop->api_version ?: '2025-01';
        $endpoint = "https://{$shop->domain}/admin/api/{$version}/graphql.json";

        $resp = Http::withHeaders([
            'X-Shopify-Access-Token' => $shop->access_token,
            'Content-Type'           => 'application/json',
        ])->post($endpoint, ['query' => $query, 'variables' => $variables]);

        $resp->throw();
        return $resp->json();
    }

    private function shouldSkipDirectImageSyncForBem(): bool
    {
        try {
            $eligibility = app(BemWatermarkEligibilityService::class);

            return $eligibility->isEnabled() && $eligibility->hasRequiredTag($this->payload);
        } catch (\Throwable $e) {
            Log::warning('BEM update image sync guard failed; keeping legacy image sync enabled', [
                'source_shop_id' => $this->sourceShopId,
                'source_product_id' => $this->sourceProductId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function syncMetaobjectMetafields(?Shop $source, Shop $target, string $sourceProductGid, string $targetProductGid): void
    {
        if (!$source) {
            // Log::warning('Metafield sync skipped: missing source shop', [
            //     'target_shop' => $target->domain,
            //     'source_product_gid' => $sourceProductGid,
            // ]);
            return;
        }

        $sourceMeta = $this->fetchSourceMetaobjectData($source, $sourceProductGid);
        if ($sourceMeta === null) {
            return;
        }

        $sourceProductId = $this->numericIdFromGid($sourceProductGid);
        $targetProductId = $this->numericIdFromGid($targetProductGid);

        $targetMapping = $this->resolveTargetMetaobjects($target, $sourceMeta['metaobjectsByKey']);

        // Log::info('Target metaobject mapping snapshot', [
        //     'source_shop'         => $source->domain,
        //     'target_shop'         => $target->domain,
        //     'source_product_gid'  => $sourceProductGid,
        //     'source_product_id'   => $sourceProductId,
        //     'target_product_gid'  => $targetProductGid,
        //     'target_product_id'   => $targetProductId,
        //     'mapping'             => $targetMapping['mapping'],
        // ]);

        $clearInputs = [];
        foreach (self::META_KEYS as $key) {
            $clearInputs[] = [
                'ownerId'   => $targetProductGid,
                'namespace' => self::META_NAMESPACE,
                'key'       => $key,
                'type'      => 'list.metaobject_reference',
                'value'     => '[]',
            ];
        }

        $clearMutation = <<<'GQL'
        mutation clearMetaobjectReferences($metafields: [MetafieldsSetInput!]!) {
          metafieldsSet(metafields: $metafields) {
            userErrors { field message }
          }
        }
        GQL;

        try {
            $clearResponse = $this->gql($target, $clearMutation, ['metafields' => $clearInputs]);
        } catch (\Throwable $e) {
            Log::error('Metafield sync failed: exception during clear step', [
                'target_shop' => $target->domain,
                'error'       => $e->getMessage(),
            ]);
            return;
        }

        if (!empty($clearResponse['errors']) || !empty($clearResponse['data']['metafieldsSet']['userErrors'] ?? [])) {
            Log::error('Metafield sync failed: errors during clear step', [
                'target_shop' => $target->domain,
                'errors'      => $clearResponse['errors'] ?? null,
                'userErrors'  => $clearResponse['data']['metafieldsSet']['userErrors'] ?? [],
            ]);
            return;
        }

        // Log::info('Metafields cleared on target shop', [
        //     'target_shop'        => $target->domain,
        //     'target_product_gid' => $targetProductGid,
        //     'target_product_id'  => $targetProductId,
        //     'cleared_keys'       => self::META_KEYS,
        // ]);

        $metafieldsInput = [];
        $metafieldsLog   = [];

        foreach (self::META_KEYS as $key) {
            $ids = $targetMapping['idsByKey'][$key] ?? [];
            if (empty($ids)) {
                if (!empty($sourceMeta['metaobjectsByKey'][$key])) {
                    Log::warning('Metafield sync missing target metaobject', [
                        'target_shop' => $target->domain,
                        'metafield'   => $key,
                    ]);
                }
                continue;
            }

            $uniqueIds = array_values(array_unique($ids));
            $metafieldsInput[] = [
                'ownerId'   => $targetProductGid,
                'namespace' => self::META_NAMESPACE,
                'key'       => $key,
                'type'      => 'list.metaobject_reference',
                'value'     => json_encode($uniqueIds, JSON_UNESCAPED_SLASHES),
            ];

            $metafieldsLog[$key] = $uniqueIds;
        }

        if (empty($metafieldsInput)) {
            Log::info('Metafield sync skipped: no target IDs to set after clear', [
                'target_shop' => $target->domain,
                'target_product_gid' => $targetProductGid,
            ]);
            return;
        }

        $mutation = <<<'GQL'
        mutation setMetaobjectReferences($metafields: [MetafieldsSetInput!]!) {
          metafieldsSet(metafields: $metafields) {
            metafields { id namespace key value type }
            userErrors { field message }
          }
        }
        GQL;

        try {
            $response = $this->gql($target, $mutation, ['metafields' => $metafieldsInput]);
        } catch (\Throwable $e) {
            Log::error('Metafield sync failed: exception during metafieldsSet', [
                'target_shop' => $target->domain,
                'error'       => $e->getMessage(),
            ]);
            return;
        }

        if (!empty($response['errors'])) {
            Log::error('Metafield sync failed: GraphQL errors on target', [
                'target_shop' => $target->domain,
                'errors'      => $response['errors'],
            ]);
            return;
        }

        $userErrors = $response['data']['metafieldsSet']['userErrors'] ?? [];
        if (!empty($userErrors)) {
            Log::error('Metafield sync failed: userErrors on target', [
                'target_shop' => $target->domain,
                'userErrors'  => $userErrors,
            ]);
            return;
        }

        // Log::info('Metafields synced to target shop', [
        //     'target_shop'        => $target->domain,
        //     'target_product_gid' => $targetProductGid,
        //     'target_product_id'  => $targetProductId,
        //     'metafields'         => $metafieldsLog,
        // ]);
    }

    private function fetchSourceMetaobjectData(Shop $source, string $productGid): ?array
    {
        $selections = [];
        foreach (self::META_KEYS as $key) {
            $selections[] = sprintf(
                '%1$s: metafield(namespace: "%2$s", key: "%3$s") { value type }',
                $key,
                self::META_NAMESPACE,
                $key
            );
        }

        $query = sprintf(
            <<<'GQL'
            query SourceMetafields($id: ID!) {
              product(id: $id) {
                %s
              }
            }
            GQL,
            implode("\n                ", $selections)
        );

        try {
            $result = $this->gql($source, $query, ['id' => $productGid]);
        } catch (\Throwable $e) {
            // Log::warning('Source metafield fetch failed: exception', [
            //     'source_shop' => $source->domain,
            //     'product_gid' => $productGid,
            //     'error'       => $e->getMessage(),
            // ]);
            return null;
        }

        if (!empty($result['errors'])) {
            // Log::warning('Source metafield fetch failed: GraphQL errors', [
            //     'source_shop' => $source->domain,
            //     'product_gid' => $productGid,
            //     'errors'      => $result['errors'],
            // ]);
            return null;
        }

        $productNode = $result['data']['product'] ?? null;
        if (!$productNode) {
            // Log::warning('Source metafield fetch: product node missing', [
            //     'source_shop' => $source->domain,
            //     'product_gid' => $productGid,
            // ]);
            return null;
        }

        $rawMetafields          = [];
        $referencesByKey        = [];
        $metaobjectIdsAggregate = [];

        foreach (self::META_KEYS as $key) {
            $node  = $productNode[$key] ?? null;
            $value = $node['value'] ?? null;
            $type  = (string)($node['type'] ?? '');

            $rawMetafields[$key] = $value;

            if (!is_string($value) || $value === '' || $type === '' || !str_contains($type, 'metaobject_reference')) {
                continue;
            }

            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (is_array($decoded)) {
                    $ids = array_values(array_filter(array_map('strval', $decoded)));
                } elseif (is_string($decoded)) {
                    $ids = [$decoded];
                } else {
                    $ids = [];
                }
            } else {
                $ids = [trim($value)];
            }

            $filtered = array_values(array_filter($ids, fn($id) => is_string($id) && $id !== ''));
            if (!$filtered) {
                continue;
            }

            $referencesByKey[$key] = $filtered;
            foreach ($filtered as $gid) {
                $metaobjectIdsAggregate[$gid] = true;
            }
        }

        $resolvedMetaobjects = [];
        if (!empty($metaobjectIdsAggregate)) {
            $metaobjectIds = array_values(array_keys($metaobjectIdsAggregate));

            $metaobjectQuery = <<<'GQL'
            query SourceMetaobjects($ids: [ID!]!) {
              nodes(ids: $ids) {
                ... on Metaobject {
                  id
                  type
                  handle
                  displayName
                  fields {
                    key
                    value
                  }
                }
              }
            }
            GQL;

            try {
                $metaobjectResult = $this->gql($source, $metaobjectQuery, ['ids' => $metaobjectIds]);
            } catch (\Throwable $e) {
                // Log::warning('Source metaobject fetch failed: exception', [
                //     'source_shop' => $source->domain,
                //     'error'       => $e->getMessage(),
                // ]);
                $metaobjectResult = null;
            }

            if ($metaobjectResult) {
                if (!empty($metaobjectResult['errors'])) {
                    // Log::warning('Source metaobject fetch failed: GraphQL errors', [
                    //     'source_shop' => $source->domain,
                    //     'errors'      => $metaobjectResult['errors'],
                    // ]);
                }

                foreach ($metaobjectResult['data']['nodes'] ?? [] as $node) {
                    if (!$node || !is_array($node)) {
                        continue;
                    }

                    $id = $node['id'] ?? null;
                    if (!$id) {
                        continue;
                    }

                    $fieldsAssoc = [];
                    foreach ($node['fields'] ?? [] as $field) {
                        $fieldKey = $field['key'] ?? null;
                        if ($fieldKey === null) {
                            continue;
                        }
                        $fieldsAssoc[$fieldKey] = $field['value'] ?? null;
                    }

                    $resolvedMetaobjects[$id] = array_filter([
                        'id'          => $id,
                        'type'        => $node['type'] ?? null,
                        'handle'      => $node['handle'] ?? null,
                        'displayName' => $node['displayName'] ?? null,
                        'fields'      => $fieldsAssoc ?: null,
                    ], fn($v) => $v !== null && $v !== []);
                }
            }
        }

        $metaobjectsByKey = [];
        foreach (self::META_KEYS as $key) {
            if (!empty($referencesByKey[$key])) {
                $metaobjectsByKey[$key] = array_map(
                    fn($gid) => $resolvedMetaobjects[$gid] ?? ['id' => $gid],
                    $referencesByKey[$key]
                );
            } else {
                $metaobjectsByKey[$key] = null;
            }
        }

        $productId = $this->numericIdFromGid($productGid);

        // Log::info('Shopify product metafields snapshot', [
        //     'shop_domain'        => $source->domain,
        //     'product_gid'        => $productGid,
        //     'product_id'         => $productId,
        //     'metafields_raw'     => $rawMetafields,
        //     'metafields_resolved'=> $metaobjectsByKey,
        // ]);

        return [
            'raw'              => $rawMetafields,
            'metaobjectsByKey' => $metaobjectsByKey,
        ];
    }

    private function resolveTargetMetaobjects(Shop $target, array $metaobjectsByKey): array
    {
        $idsByKey = [];
        $mapping  = [];

        foreach ($metaobjectsByKey as $key => $entries) {
            if (!is_array($entries) || empty($entries)) {
                $mapping[$key] = [];
                continue;
            }

            foreach ($entries as $entry) {
                $type      = $entry['type'] ?? null;
                $handle    = $entry['handle'] ?? null;
                $sourceId  = $entry['id'] ?? null;
                $targetMeta = null;
                $status     = 'mapped';

                if ($type && $handle) {
                    $targetMeta = $this->fetchTargetMetaobjectByHandle($target, $type, $handle);
                    if ($targetMeta && !empty($targetMeta['id'])) {
                        $idsByKey[$key][] = $targetMeta['id'];
                    } else {
                        $status = 'not_found';
                    }
                } else {
                    $status = 'missing_type_or_handle';
                }

                $mapping[$key][] = [
                    'source_id'           => $sourceId,
                    'type'                => $type,
                    'handle'              => $handle,
                    'target_id'           => $targetMeta['id'] ?? null,
                    'target_handle'       => $targetMeta['handle'] ?? null,
                    'target_display_name' => $targetMeta['displayName'] ?? null,
                    'status'              => $status,
                ];
            }
        }

        return [
            'idsByKey' => $idsByKey,
            'mapping'  => $mapping,
        ];
    }

    private function fetchTargetMetaobjectByHandle(Shop $shop, string $type, string $handle): ?array
    {
        $cacheKey = strtolower($type).'|'.strtolower($handle);
        if (array_key_exists($cacheKey, $this->targetMetaobjectCache)) {
            return $this->targetMetaobjectCache[$cacheKey];
        }

        $query = <<<'GQL'
        query TargetMetaobjectByHandle($handle: MetaobjectHandleInput!) {
          metaobjectByHandle(handle: $handle) {
            id
            type
            handle
            displayName
            fields {
              key
              value
            }
          }
        }
        GQL;

        try {
            $result = $this->gql($shop, $query, ['handle' => ['type' => $type, 'handle' => $handle]]);
        } catch (\Throwable $e) {
            // Log::warning('Target metaobject lookup failed: exception', [
            //     'target_shop' => $shop->domain,
            //     'type'        => $type,
            //     'handle'      => $handle,
            //     'error'       => $e->getMessage(),
            // ]);
            return $this->targetMetaobjectCache[$cacheKey] = null;
        }

        if (!empty($result['errors'])) {
            // Log::warning('Target metaobject lookup failed: GraphQL errors', [
            //     'target_shop' => $shop->domain,
            //     'type'        => $type,
            //     'handle'      => $handle,
            //     'errors'      => $result['errors'],
            // ]);
            return $this->targetMetaobjectCache[$cacheKey] = null;
        }

        $node = $result['data']['metaobjectByHandle'] ?? null;
        if (!$node) {
            return $this->targetMetaobjectCache[$cacheKey] = null;
        }

        $fieldsAssoc = [];
        foreach ($node['fields'] ?? [] as $field) {
            $fieldKey = $field['key'] ?? null;
            if ($fieldKey === null) {
                continue;
            }
            $fieldsAssoc[$fieldKey] = $field['value'] ?? null;
        }

        $normalized = array_filter([
            'id'          => $node['id'] ?? null,
            'type'        => $node['type'] ?? null,
            'handle'      => $node['handle'] ?? null,
            'displayName' => $node['displayName'] ?? null,
            'fields'      => $fieldsAssoc ?: null,
        ], fn($v) => $v !== null && $v !== []);

        return $this->targetMetaobjectCache[$cacheKey] = $normalized;
    }

    // === Product-level ===

    private function computeProductDiff(array $payload): array
    {
        $diff = [];

        // title
        $title = $payload['title'] ?? null;
        if ($title !== null) {
            $diff['title'] = $title;
        }

        // handle / URL slug
        $handle = $payload['handle'] ?? null;
        if ($handle !== null && trim((string) $handle) !== '') {
            $diff['handle'] = (string) $handle;
        }

        // body_html -> descriptionHtml
        $desc = $payload['body_html'] ?? null;
        if ($desc !== null) {
            $diff['descriptionHtml'] = $desc;
        }

        // vendor
        $vendor = $payload['vendor'] ?? null;
        if ($vendor !== null) {
            $diff['vendor'] = $vendor;
        }

        // product_type -> productType
        $ptype = $payload['product_type'] ?? null;
        if ($ptype !== null) {
            $diff['productType'] = $ptype;
        }

        // tags (string comma) -> set
        if (array_key_exists('tags', $payload)) {
            $newTags = $this->splitTags($payload['tags'] ?? '');
            $diff['tags'] = $newTags;
        }

        // status (REST: active/draft/archived) -> enum
        if (array_key_exists('status', $payload)) {
            $map = ['active' => 'ACTIVE', 'draft' => 'DRAFT', 'archived' => 'ARCHIVED'];
            $new = $map[strtolower((string)$payload['status'])] ?? null;
            if ($new) {
                $diff['status'] = $new;
            }
        }

        return $diff;
    }

    private function productUpdate(Shop $shop, string $targetProductGid, array $patch): void
    {
        $mutation = <<<'GQL'
        mutation productUpdate($product: ProductUpdateInput!) {
        productUpdate(product: $product) {
            product { id }
            userErrors { field message }
        }
        }
        GQL;

        $input = array_merge(['id' => $targetProductGid], $patch);

        //Log::info('productUpdate payload', ['target' => $shop->domain, 'fields' => array_keys($patch)]);
        $res = $this->gql($shop, $mutation, ['product' => $input]);

        if (!empty($res['errors'])) {
            Log::error('productUpdate top-level errors', ['target' => $shop->domain, 'errors' => $res['errors']]);
            throw new \RuntimeException('GraphQL errors: ' . json_encode($res['errors']));
        }
        $ue = $res['data']['productUpdate']['userErrors'] ?? [];
        if (!empty($ue)) {
            Log::error('productUpdate userErrors', ['target' => $shop->domain, 'userErrors' => $ue]);
            throw new \RuntimeException('productUpdate userErrors: ' . json_encode($ue));
        }
    }


    private function normalizeProductSnapshot(array $p): array
    {
        // imagini
        $imgs = $this->extractSourceImages($p);

        // opțiuni
        $opts = $this->normalizeOptionsFromPayload($p);

        return [
            'id'                 => $p['id'] ?? null,
            'title'              => $p['title'] ?? null,
            'body_html'          => $p['body_html'] ?? null,
            'vendor'             => $p['vendor'] ?? null,
            'product_type'       => $p['product_type'] ?? null,
            'tags'               => $p['tags'] ?? null,
            'status'             => $p['status'] ?? null,

            // images
            'images'             => $imgs,
            'images_fingerprint' => $this->fingerprintImages($imgs),

            // options
            'options'            => $opts,
            'options_fingerprint'=> $this->optionsFingerprint($opts),
        ];
    }


    private function splitTags(?string $s): array
    {
        $arr = array_map('trim', explode(',', $s ?? ''));
        $arr = array_values(array_filter($arr, fn($x) => $x !== ''));
        sort($arr, SORT_NATURAL | SORT_FLAG_CASE);
        return $arr;
    }

    private function canonUrl(?string $url): ?string
    {
        if (!$url) return null;
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || empty($parts['path'])) return $url;
        $host = strtolower($parts['host']);
        $scheme = ($parts['scheme'] ?? 'https') === 'http' ? 'http' : 'https';
        return $scheme.'://'.$host.$parts['path']; // fără query ?v=
    }

    private function extractSourceImages(array $src): array
    {
        $out = [];
        // preferă $src['images'] dacă există (REST webhook); dacă nu, pică pe $src['media']
        if (!empty($src['images']) && is_array($src['images'])) {
            foreach ($src['images'] as $i => $img) {
                $srcUrl = $img['src'] ?? null;
                $out[] = [
                    'src'       => $srcUrl,
                    'src_canon' => $this->canonUrl($srcUrl),
                    'alt'       => $img['alt'] ?? '',
                    'position'  => (int)($img['position'] ?? ($i+1)),
                ];
            }
        } elseif (!empty($src['media']) && is_array($src['media'])) {
            foreach ($src['media'] as $i => $m) {
                if (($m['media_content_type'] ?? '') !== 'IMAGE') continue;
                $srcUrl = $m['preview_image']['src'] ?? null;
                $out[] = [
                    'src'       => $srcUrl,
                    'src_canon' => $this->canonUrl($srcUrl),
                    'alt'       => $m['alt'] ?? '',
                    'position'  => (int)($m['position'] ?? ($i+1)),
                ];
            }
        }
        // normalizează: ordonează după position
        usort($out, fn($a,$b) => ($a['position'] <=> $b['position']));
        return $out;
    }

    private function fingerprintImages(array $imgs): string
    {
        // fingerprint determinist pe (src_canon + alt) în ordinea pozițiilor
        $pieces = [];
        foreach ($imgs as $im) {
            $pieces[] = ($im['src_canon'] ?? '').'|'.(string)($im['alt'] ?? '');
        }
        return 'sha1:'.sha1(implode('||', $pieces));
    }

    private function fetchTargetMedia(Shop $shop, string $productGid): array
    {
        $q = <<<'GQL'
        query($id: ID!) {
        product(id: $id) {
            media(first: 250) {
            nodes {
                id
                mediaContentType
                ... on MediaImage {
                image { url alt }
                }
            }
            }
        }
        }
        GQL;
        $r = $this->gql($shop, $q, ['id' => $productGid]);
        return $r['data']['product']['media']['nodes'] ?? [];
    }

    private function deleteAllMedia(Shop $shop, string $productGid, array $mediaNodes): void
    {
        if (!$mediaNodes) return;

        $ids = array_values(array_filter(array_map(fn($n) => $n['id'] ?? null, $mediaNodes)));
        Log::info('Deleting media', ['target' => $shop->domain, 'count' => count($ids), 'ids' => $ids]);

        $m = <<<'GQL'
        mutation($productId: ID!, $mediaIds: [ID!]!) {
        productDeleteMedia(productId: $productId, mediaIds: $mediaIds) {
            deletedMediaIds
            userErrors { field message }
        }
        }
        GQL;

        $res = $this->gql($shop, $m, ['productId' => $productGid, 'mediaIds' => $ids]);

        if (!empty($res['errors'])) {
            Log::error('productDeleteMedia top-level errors', ['target' => $shop->domain, 'errors' => $res['errors']]);
            throw new \RuntimeException('productDeleteMedia errors: '.json_encode($res['errors']));
        }

        $ue = $res['data']['productDeleteMedia']['userErrors'] ?? [];
        if (!empty($ue)) {
            Log::error('productDeleteMedia userErrors', ['target' => $shop->domain, 'userErrors' => $ue]);
            throw new \RuntimeException('productDeleteMedia userErrors: '.json_encode($ue));
        }

        $deleted = $res['data']['productDeleteMedia']['deletedMediaIds'] ?? [];
        Log::info('productDeleteMedia done', ['target' => $shop->domain, 'deleted_count' => count($deleted), 'deleted' => $deleted]);
    }


    private function createMedia(Shop $shop, string $productGid, array $imgs): void
    {
        if (!$imgs) return;
        $media = [];
        foreach ($imgs as $i) {
            if (empty($i['src'])) continue;
            $media[] = array_filter([
                'mediaContentType' => 'IMAGE',
                'originalSource'   => $i['src'],   // Shopify va descărca din CDN
                'alt'              => $i['alt'] ?? null,
            ], fn($v) => $v !== null);
        }

        if (!$media) return;

        $m = <<<'GQL'
        mutation UpdateProductWithNewMedia($product: ProductUpdateInput!, $media: [CreateMediaInput!]) {
        productUpdate(product: $product, media: $media) {
            product { id }
            userErrors { field message }
        }
        }
        GQL;

        $res = $this->gql($shop, $m, ['product' => ['id' => $productGid], 'media' => $media]);
        $ue  = $res['data']['productUpdate']['userErrors'] ?? [];
        if (!empty($ue)) throw new \RuntimeException('productUpdate(media) userErrors: '.json_encode($ue));
    }

    /**
     * Implementare simplă: șterge tot & recreează în ordinea din sursă.
     * (Poți înlocui ulterior cu diff granular create/delete/update/reorder.)
     */
    private function syncImagesReplaceAll(Shop $shop, string $productGid, array $srcImages): void
    {
        // 1) Șterge tot din media (nou)
        $existingMedia = $this->fetchTargetMedia($shop, $productGid);
        $this->deleteAllMedia($shop, $productGid, $existingMedia);

        // 2) Șterge tot din vechea colecție de imagini (legacy) via REST
        $existingImages = $this->fetchTargetProductImages($shop, $productGid);
        $this->deleteAllProductImagesRest($shop, $productGid, $existingImages);

        // 3) Recreează din sursă via media (CreateMediaInput)
        $this->createMedia($shop, $productGid, $srcImages);
    }


    private function fetchTargetProductImages(Shop $shop, string $productGid): array
    {
        $q = <<<'GQL'
        query($id: ID!) {
        product(id: $id) {
            images(first: 250) {
            nodes { id }
            }
        }
        }
        GQL;

        $r = $this->gql($shop, $q, ['id' => $productGid]);
        return $r['data']['product']['images']['nodes'] ?? [];
    }

    

    // Extrage ID-ul numeric dintr-un GID (ex: gid://shopify/Product/1234567890 -> 1234567890)
    private function numericIdFromGid(string $gid): ?string
    {
        if (!$gid) return null;
        $pos = strrpos($gid, '/');
        return $pos === false ? null : substr($gid, $pos + 1);
    }


    private function fetchMetaDescriptionFromSource(): ?string
    {
        try {
            $source = Shop::find($this->sourceShopId);
            if (!$source) {
                Log::warning('Meta description fetch skipped: missing source shop (update job)', [
                    'source_shop_id'    => $this->sourceShopId,
                    'source_product_id' => $this->sourceProductId,
                ]);
                return null;
            }

            $productGid = 'gid://shopify/Product/' . $this->sourceProductId;
            $query = <<<'GQL'
            query($id: ID!) {
              product(id: $id) {
                seo { description }
                metafield(namespace: "global", key: "description_tag") { value }
              }
            }
            GQL;

            $res = $this->gql($source, $query, ['id' => $productGid]);
            $seoDesc  = $res['data']['product']['seo']['description'] ?? null;
            $metaDesc = $res['data']['product']['metafield']['value'] ?? null;

            $description = null;
            if (is_string($seoDesc) && trim($seoDesc) !== '') {
                $description = trim($seoDesc);
            } elseif (is_string($metaDesc) && trim($metaDesc) !== '') {
                $description = trim($metaDesc);
            }

            // Log::info('Source product meta description (update job)', [
            //     'source_shop_id'    => $this->sourceShopId,
            //     'source_product_id' => $this->sourceProductId,
            //     'meta_description'  => $description,
            // ]);

            return $description;
        } catch (\Throwable $e) {
            Log::warning('Meta description fetch failed (update job)', [
                'source_shop_id'    => $this->sourceShopId,
                'source_product_id' => $this->sourceProductId,
                'error'             => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function applyMetaDescriptionToTarget(Shop $shop, string $productGid, string $description): void
    {
        try {
            $mutation = <<<'GQL'
            mutation($product: ProductUpdateInput!) {
              productUpdate(product: $product) {
                product { id }
                userErrors { field message }
              }
            }
            GQL;

            $input = [
                'id' => $productGid,
                'seo' => ['description' => $description],
            ];

            $res = $this->gql($shop, $mutation, ['product' => $input]);
            $ue  = $res['data']['productUpdate']['userErrors'] ?? [];
            if (!empty($ue)) {
                Log::warning('Meta description SEO update userErrors (update job)', [
                    'target_shop' => $shop->domain,
                    'product_gid' => $productGid,
                    'userErrors'  => $ue,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Meta description SEO update failed (update job)', [
                'target_shop' => $shop->domain,
                'product_gid' => $productGid,
                'error'       => $e->getMessage(),
            ]);
        }

        try {
            $mutation = <<<'GQL'
            mutation($metafields: [MetafieldsSetInput!]!) {
              metafieldsSet(metafields: $metafields) {
                metafields { id }
                userErrors { field message }
              }
            }
            GQL;

            $vars = [
                'metafields' => [[
                    'ownerId'   => $productGid,
                    'namespace' => 'global',
                    'key'       => 'description_tag',
                    'type'      => 'single_line_text_field',
                    'value'     => $description,
                ]],
            ];

            $res = $this->gql($shop, $mutation, $vars);
            $ue  = $res['data']['metafieldsSet']['userErrors'] ?? [];
            if (!empty($ue)) {
                Log::warning('Meta description metafield userErrors (update job)', [
                    'target_shop' => $shop->domain,
                    'product_gid' => $productGid,
                    'userErrors'  => $ue,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Meta description metafield update failed (update job)', [
                'target_shop' => $shop->domain,
                'product_gid' => $productGid,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Șterge imaginile din colecția legacy ProductImage via REST:
     * DELETE /admin/api/{version}/products/{product_id}/images/{image_id}.json
     */
    private function deleteAllProductImagesRest(Shop $shop, string $productGid, array $imageNodes): void
    {
        if (!$imageNodes) return;

        $version = $shop->api_version ?: '2025-01';
        $productId = $this->numericIdFromGid($productGid);
        if (!$productId) return;

        foreach ($imageNodes as $node) {
            $gid = $node['id'] ?? null;
            $imageId = $gid ? $this->numericIdFromGid($gid) : null;
            if (!$imageId) continue;

            $url = "https://{$shop->domain}/admin/api/{$version}/products/{$productId}/images/{$imageId}.json";

            try {
                $resp = Http::withHeaders([
                    'X-Shopify-Access-Token' => $shop->access_token,
                    'Content-Type'           => 'application/json',
                ])->delete($url);

                if ($resp->failed()) {
                    Log::warning('REST delete ProductImage failed', [
                        'target'   => $shop->domain,
                        'product'  => $productId,
                        'image'    => $imageId,
                        'status'   => $resp->status(),
                        'body'     => $resp->body(),
                    ]);
                } else {
                    // Log::info('REST deleted ProductImage', [
                    //     'target'  => $shop->domain,
                    //     'product' => $productId,
                    //     'image'   => $imageId,
                    // ]);
                }
            } catch (\Throwable $e) {
                Log::error('REST delete ProductImage exception', [
                    'target'  => $shop->domain,
                    'product' => $productId,
                    'image'   => $imageId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Extrage opțiunile din payload-ul sursă într-un format canonic:
     * [
     *   ['name' => 'Color', 'values' => ['Red','Blue']],
     *   ['name' => 'Size',  'values' => ['M','L']]
     * ]
     */
    private function normalizeOptionsFromPayload(array $payload): array
    {
        $out = [];
        $opts = $payload['options'] ?? [];
        if (!is_array($opts)) return $out;

        // Respectăm ordinea din payload (position), dacă există
        usort($opts, function($a, $b) {
            $pa = (int)($a['position'] ?? 0);
            $pb = (int)($b['position'] ?? 0);
            return $pa <=> $pb;
        });

        foreach ($opts as $opt) {
            $name = trim((string)($opt['name'] ?? ''));
            if ($name === '') continue;

            $values = $opt['values'] ?? [];
            if (!is_array($values)) $values = [];

            // valori unice, păstrând ordinea
            $seen = [];
            $vals = [];
            foreach ($values as $v) {
                $sv = (string)$v;
                if (!isset($seen[$sv])) {
                    $seen[$sv] = true;
                    $vals[] = $sv;
                }
            }

            $out[] = ['name' => $name, 'values' => $vals];
        }

        return $out;
    }

    private function optionsFingerprint(array $opts): string
    {
        $pieces = [];
        foreach ($opts as $o) {
            $n = mb_strtolower($o['name'] ?? '');
            $vals = array_map(fn($v) => (string)$v, $o['values'] ?? []);
            $pieces[] = $n.':'.implode('|', $vals);
        }
        return 'sha1:'.sha1(implode('||', $pieces));
    }

    // Detectează produsul cu varianta implicită (fără opțiuni reale)
    private function isDefaultProduct(array $sourceOptions): bool
    {
        if (empty($sourceOptions)) return true;
        if (count($sourceOptions) === 1) {
            $name = strtolower((string)($sourceOptions[0]['name'] ?? ''));
            return $name === 'title';
        }
        return false;
    }


    /**
     * Construiește cheia canonică a unei variante pe baza ordinii opțiunilor:
     * ex: "color=red|size=m"
     */
    private function variantKey(array $variant, array $optionNames): string
    {
        $parts = [];
        foreach ($optionNames as $i => $name) {
            $field = 'option'.($i+1);
            $val   = (string)($variant[$field] ?? '');
            $parts[] = mb_strtolower($name).'='.mb_strtolower($val);
        }
        return implode('|', $parts);
    }

    /**
     * Fingerprint pentru o variantă: stabile, pe câmpurile „economice” (fără stoc).
     * Include și imaginea (canon URL) dacă e prezentă pe variantă.
     */
    private function variantFingerprint(array $v): string
    {
        $pieces = [
            (string)($v['price'] ?? ''),
            (string)($v['compare_at_price'] ?? ''),
            (string)($v['taxable'] ?? ''),
            (string)($v['inventory_policy'] ?? ''),
        ];
        return 'sha1:' . sha1(implode('|', $pieces));
    }

    /**
     * Fingerprint pentru inventar (separat de celelalte atribute).
     */
    private function inventoryFingerprint(array $v): string
    {
        $qty = (string)($v['inventory_quantity'] ?? '');
        return 'sha1:'.sha1($qty);
    }

    /**
     * Normalizează variantele sursă într-o listă; cheia opțiunilor rămâne
     * doar date pentru mutații, nu identitate de corelare.
     */
    private function normalizeSourceVariants(array $payload, array $optionNames): array
    {
        $out = [];
        $vars = $payload['variants'] ?? [];
        if (!is_array($vars)) return $out;

        foreach ($vars as $v) {
            $key = $this->variantKey($v, array_map(fn($o) => $o['name'], $optionNames));
            $imgSrc = $v['image_id'] ?? null;
            // Din payload REST nu vine URL direct pe variantă; încercăm fallback:
            $imageSrcCanon = null;
            if (!empty($v['image_id']) && !empty($payload['images'])) {
                foreach ($payload['images'] as $img) {
                    if (($img['id'] ?? null) == $v['image_id']) {
                        $imageSrcCanon = $this->canonUrl($img['src'] ?? null);
                        break;
                    }
                }
            }

            $imPresent = array_key_exists('inventory_management', $v);
            $imValue   = $imPresent ? ($v['inventory_management'] ?? null) : null;

            $norm = [
                'source_variant_id'   => $v['id'] ?? null,
                'source_options_key'  => $this->canonKey($key),
                'sku'                 => $v['sku'] ?? null,
                'barcode'             => $v['barcode'] ?? null,
                'price'               => $v['price'] ?? null,
                'compare_at_price'    => $v['compare_at_price'] ?? null,
                'taxable'             => $v['taxable'] ?? null,
                'weight'              => $v['weight'] ?? null,
                'weight_unit'         => $v['weight_unit'] ?? null,
                'inventory_policy'    => $v['inventory_policy'] ?? null,
                // păstrăm valoarea și prezența câmpului exact cum vine din payload
                'inventory_management'=> $imValue,
                'inventory_management_present' => $imPresent,
                'inventory_quantity'  => $v['inventory_quantity'] ?? null,
                'image_src_canon'     => $imageSrcCanon,
            ];

            $norm['variant_fingerprint']   = $this->variantFingerprint($norm);
            $norm['inventory_fingerprint'] = $this->inventoryFingerprint($norm);

            $out[] = $norm;
        }

        return $out;
    }

    /**
     * Construiește vectorul de valori (în ordinea opțiunilor produsului) pentru create.
     * Ex: key "color=Red|size=M" + options [Color, Size] -> ["Red","M"]
     */
    private function buildOptionsArrayForCreate(string $rawKey, array $srcOptions): array
    {
        $pairs = array_filter(explode('|', $rawKey));
        $map = [];
        foreach ($pairs as $p) {
            [$n, $v] = array_pad(explode('=', $p, 2), 2, '');
            $map[$this->canonOptName($n)] = $this->canonOptVal($v);
        }

        $out = [];
        foreach ($srcOptions as $opt) {
            $nCanon = $this->canonOptName($opt['name'] ?? '');
            $desired = $map[$nCanon] ?? '';
            // păstrează capitalizarea originală din lista de valori a opțiunii
            $original = $desired;
            foreach (($opt['values'] ?? []) as $candidate) {
                if ($this->canonOptVal((string)$candidate) === $desired) {
                    $original = (string)$candidate;
                    break;
                }
            }
            $out[] = $original;
        }
        return $out;
    }

    /**
     * Creează o variantă pe target folosind ProductVariantInput (2025-01)
     * Acceptă "options" ca listă de valori în ordinea opțiunilor produsului.
     */
    private function productVariantCreate(Shop $shop, string $productGid, array $optionsValues, array $srcVariant): array
    {
        $m = <<<'GQL'
        mutation productVariantCreate($input: ProductVariantInput!) {
          productVariantCreate(input: $input) {
            productVariant { id legacyResourceId inventoryItem { id } }
            userErrors { field message code }
          }
        }
        GQL;

        $input = array_filter([
            'productId' => $productGid,
            'options'   => array_values($optionsValues),
            'price'     => isset($srcVariant['price']) ? (string)$srcVariant['price'] : null,
            'compareAtPrice' => isset($srcVariant['compare_at_price']) ? (string)$srcVariant['compare_at_price'] : null,
            // conform pattern-ului folosit în alte joburi din repo
            'inventoryItem' => array_filter([
                'sku' => $srcVariant['sku'] ?? null,
            ], fn($v) => $v !== null && $v !== ''),
        ], fn($v) => $v !== null && $v !== []);

        $res = $this->gql($shop, $m, ['input' => $input]);

        if (!empty($res['errors'])) {
            Log::error('productVariantCreate top-level errors', ['target' => $shop->domain, 'errors' => $res['errors']]);
            throw new \RuntimeException('productVariantCreate errors: '.json_encode($res['errors']));
        }
        $ue = $res['data']['productVariantCreate']['userErrors'] ?? [];
        if (!empty($ue)) {
            Log::error('productVariantCreate userErrors', ['target' => $shop->domain, 'userErrors' => $ue]);
            throw new \RuntimeException('productVariantCreate userErrors: '.json_encode($ue));
        }

        $pv = $res['data']['productVariantCreate']['productVariant'] ?? null;
        return [
            'variant_gid'        => $pv['id'] ?? null,
            'variant_legacy_id'  => $pv['legacyResourceId'] ?? null,
            'inventory_item_gid' => $pv['inventoryItem']['id'] ?? null,
        ];
    }

    private function productVariantsBulkDelete(
        Shop $shop,
        string $productGid,
        array $variantGids
    ): void
    {
        $variantGids = array_values(array_unique(array_filter($variantGids)));
        if (!$variantGids) {
            return;
        }

        $m = <<<'GQL'
        mutation deleteProductVariants($productId: ID!, $variantsIds: [ID!]!) {
          productVariantsBulkDelete(productId: $productId, variantsIds: $variantsIds) {
            product { id }
            userErrors { field message code }
          }
        }
        GQL;

        $res = $this->gql($shop, $m, [
            'productId' => $productGid,
            'variantsIds' => $variantGids,
        ]);

        if (!empty($res['errors'])) {
            Log::error('productVariantsBulkDelete top-level errors', [
                'target' => $shop->domain,
                'errors' => $res['errors'],
            ]);
            throw new \RuntimeException('productVariantsBulkDelete errors: '.json_encode($res['errors']));
        }

        $ue = $res['data']['productVariantsBulkDelete']['userErrors'] ?? [];
        if (!empty($ue)) {
            Log::error('productVariantsBulkDelete userErrors', [
                'target' => $shop->domain,
                'userErrors' => $ue,
            ]);
            throw new \RuntimeException('productVariantsBulkDelete userErrors: '.json_encode($ue));
        }
    }

    /**
     * Bulk create variants for update flow using ProductVariantsBulkCreate.
     * Returns a map keyed by source variant ID. Option values are request data,
     * never an identity used to rediscover the created variant.
     */
    private function productVariantsBulkCreateForUpdate(Shop $shop, string $productGid, array $toCreate, array $srcOptions, bool $hasExistingVariants = false): array
    {
        if (empty($toCreate)) return [];

        // Build ProductVariantsBulkInput[]
        $variantsInput = [];
        $sourceIds = [];
        $optionNames = array_map(fn($o) => (string)($o['name'] ?? ''), $srcOptions);

        foreach ($toCreate as $sourceIdKey => $sv) {
            $sourceId = (int) ($sv['source_variant_id'] ?? 0);
            if ($sourceId <= 0) {
                throw new \RuntimeException('Cannot create target variant without source variant ID');
            }
            if ((string) $sourceId !== (string) $sourceIdKey) {
                throw new \RuntimeException('Variant create map is not keyed by source variant ID');
            }
            if (in_array((string) $sourceId, $sourceIds, true)) {
                throw new \RuntimeException('Duplicate source variant ID in update payload: '.$sourceId);
            }

            $sourceIds[] = (string) $sourceId;
            // optionValues [{name, optionName}]
            $ov = [];
            $rawKey = (string) ($sv['source_options_key'] ?? '');
            $pairs = array_filter(explode('|', (string)$rawKey));
            $map = [];
            foreach ($pairs as $p) { [$n,$v] = array_pad(explode('=', $p, 2), 2, ''); $map[$this->canonOptName($n)] = $this->canonOptVal($v); }

            foreach ($optionNames as $i => $optName) {
                $nCanon = $this->canonOptName($optName);
                $vCanon = $map[$nCanon] ?? '';
                $value  = $vCanon;
                foreach (($srcOptions[$i]['values'] ?? []) as $candidate) {
                    if ($this->canonOptVal((string)$candidate) === $vCanon) { $value = (string)$candidate; break; }
                }
                if ($optName !== '' && $value !== '') {
                    $ov[] = ['name' => $value, 'optionName' => $optName];
                }
            }

            $variantsInput[] = array_filter([
                'barcode'         => $sv['barcode'] ?? null,
                'price'           => isset($sv['price']) ? (float)$sv['price'] : null,
                'compareAtPrice'  => isset($sv['compare_at_price']) ? (float)$sv['compare_at_price'] : null,
                'inventoryPolicy' => isset($sv['inventory_policy']) && strtolower((string)$sv['inventory_policy']) === 'continue' ? 'CONTINUE' : 'DENY',
                'optionValues'    => $ov,
                'inventoryItem'   => array_filter([
                    'sku' => $sv['sku'] ?? null,
                ], fn($x) => $x !== null && $x !== ''),
                'metafields'      => [[
                    'namespace' => 'custom',
                    'key' => 'parentvariant',
                    'type' => 'number_integer',
                    'value' => (string) $sourceId,
                ]],
            ], fn($x) => $x !== null && $x !== []);
        }

        // REMOVE_STANDALONE_VARIANT șterge varianta existentă dacă produsul are o singură variantă
        // ("standalone"). Când target-ul deja are variante reale (hasExistingVariants=true),
        // nu trimitem strategia ca Shopify să nu distrugă variantele existente.
        if ($hasExistingVariants) {
            $mutation = <<<'GQL'
            mutation CreateVariants($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
              productVariantsBulkCreate(productId: $productId, variants: $variants) {
                productVariants {
                  id
                  metafield(namespace: "custom", key: "parentvariant") { value }
                  inventoryItem { id }
                }
                userErrors { field message }
              }
            }
            GQL;
            $gqlVars = ['productId' => $productGid, 'variants' => $variantsInput];
        } else {
            $mutation = <<<'GQL'
            mutation CreateVariants($productId: ID!, $variants: [ProductVariantsBulkInput!]!, $strategy: ProductVariantsBulkCreateStrategy!) {
              productVariantsBulkCreate(productId: $productId, variants: $variants, strategy: $strategy) {
                productVariants {
                  id
                  metafield(namespace: "custom", key: "parentvariant") { value }
                  inventoryItem { id }
                }
                userErrors { field message }
              }
            }
            GQL;
            $gqlVars = ['productId' => $productGid, 'variants' => $variantsInput, 'strategy' => 'REMOVE_STANDALONE_VARIANT'];
        }

        $res = $this->gql($shop, $mutation, $gqlVars);

        if (!empty($res['errors'])) {
            Log::error('productVariantsBulkCreate top-level errors', ['target'=>$shop->domain, 'errors'=>$res['errors']]);
            throw new \RuntimeException('productVariantsBulkCreate errors: '.json_encode($res['errors']));
        }
        $ue = $res['data']['productVariantsBulkCreate']['userErrors'] ?? [];
        if (!empty($ue)) {
            Log::error('productVariantsBulkCreate userErrors', ['target'=>$shop->domain, 'userErrors'=>$ue]);
            throw new \RuntimeException('productVariantsBulkCreate userErrors: '.json_encode($ue));
        }

        $created = $res['data']['productVariantsBulkCreate']['productVariants'] ?? [];
        if (count($created) !== count($sourceIds)) {
            throw new \RuntimeException(sprintf(
                'Shopify returned %d created variants for %d requested source variants',
                count($created),
                count($sourceIds)
            ));
        }

        $out = [];
        foreach ($created as $node) {
            $sourceId = (string) ($node['metafield']['value'] ?? '');
            $variantGid = $node['id'] ?? null;
            if (!in_array($sourceId, $sourceIds, true) || isset($out[$sourceId]) || !$variantGid) {
                throw new \RuntimeException('Shopify returned an invalid or duplicate parentvariant after variant create');
            }

            $out[$sourceId] = [
                'source_variant_id' => (int) $sourceId,
                'variant_id' => $this->numericIdFromGid($variantGid),
                'variant_gid'        => $node['id'] ?? null,
                'inventory_item_gid' => $node['inventoryItem']['id'] ?? null,
            ];
        }

        return $out;
    }

    private function buildOptionCreateInputsFromPayload(array $src): array
    {
        $opts = $src['options'] ?? [];
        if (!is_array($opts) || empty($opts)) return [];

        $result = [];
        foreach ($opts as $idx => $opt) {
            $name = trim((string)($opt['name'] ?? ''));
            if ($name === '' || strtolower($name) === 'title') {
                // ignorăm "Title" (fallback Shopify pentru produse fără opțiuni)
                continue;
            }
            $values = $opt['values'] ?? [];
            $values = is_array($values) ? $values : [];
            $values = array_values(array_filter(array_map(fn($v) => ['name' => (string)$v], $values), fn($v) => $v['name'] !== ''));

            $result[] = array_filter([
                'name'     => $name,
                'position' => $idx + 1,
                'values'   => $values,    // listă de { name }
            ], fn($v) => $v !== null && $v !== []);
        }
        return $result;
    }

    private function fetchTargetOptions(Shop $shop, string $productGid): array
    {
        $q = <<<'GQL'
        query($id: ID!) {
        product(id: $id) {
            options {
            id
            name
            values
            position
            optionValues {
              id
              name
              hasVariants
            }
            }
        }
        }
        GQL;

        $r = $this->gql($shop, $q, ['id' => $productGid]);
        return $r['data']['product']['options'] ?? [];
    }


    private function productOptionsCreate(Shop $shop, string $productGid, array $options, string $variantStrategy = 'LEAVE_AS_IS'): void
    {
        $m = <<<'GQL'
        mutation createOptions($productId: ID!, $options: [OptionCreateInput!]!, $variantStrategy: ProductOptionCreateVariantStrategy) {
        productOptionsCreate(productId: $productId, options: $options, variantStrategy: $variantStrategy) {
            product { id }
            userErrors { field message code }
        }
        }
        GQL;

        $vars = [
            'productId'       => $productGid,
            'options'         => $options,
            'variantStrategy' => $variantStrategy, // LEAVE_AS_IS sau CREATE
        ];

        $res = $this->gql($shop, $m, $vars);

        if (!empty($res['errors'])) {
            Log::error('productOptionsCreate top-level errors', ['target' => $shop->domain, 'errors' => $res['errors']]);
            throw new \RuntimeException('GraphQL errors: ' . json_encode($res['errors']));
        }
        $ue = $res['data']['productOptionsCreate']['userErrors'] ?? [];
        if (!empty($ue)) {
            Log::error('productOptionsCreate userErrors', ['target' => $shop->domain, 'userErrors' => $ue]);
            throw new \RuntimeException('productOptionsCreate userErrors: ' . json_encode($ue));
        }
    }

    private function productOptionsDelete(
        Shop $shop,
        string $productGid,
        array $optionGids,
        string $strategy = 'POSITION'
    ): void {
        $optionGids = array_values(array_unique(array_filter($optionGids)));
        if (!$optionGids) {
            return;
        }

        $mutation = <<<'GQL'
        mutation deleteOptions(
          $productId: ID!
          $options: [ID!]!
          $strategy: ProductOptionDeleteStrategy
        ) {
          productOptionsDelete(
            productId: $productId
            options: $options
            strategy: $strategy
          ) {
            product { id }
            userErrors { field message code }
          }
        }
        GQL;

        $response = $this->gql($shop, $mutation, [
            'productId' => $productGid,
            'options' => $optionGids,
            'strategy' => $strategy,
        ]);

        if (!empty($response['errors'])) {
            Log::error('productOptionsDelete top-level errors', [
                'target' => $shop->domain,
                'errors' => $response['errors'],
            ]);
            throw new \RuntimeException(
                'productOptionsDelete top-level errors: '.json_encode($response['errors'])
            );
        }

        $userErrors = $response['data']['productOptionsDelete']['userErrors'] ?? [];
        if ($userErrors) {
            Log::error('productOptionsDelete userErrors', [
                'target' => $shop->domain,
                'userErrors' => $userErrors,
            ]);
            throw new \RuntimeException(
                'productOptionsDelete userErrors: '.json_encode($userErrors)
            );
        }
    }

    private function productOptionUpdate(
        Shop $shop,
        string $productGid,
        array $option,
        array $optionValuesToUpdate,
        array $optionValuesToDelete
    ): void
    {
        $m = <<<'GQL'
        mutation updateOption(
          $productId: ID!
          $option: OptionUpdateInput!
          $optionValuesToUpdate: [OptionValueUpdateInput!]
          $optionValuesToDelete: [ID!]
        ) {
          productOptionUpdate(
            productId: $productId
            option: $option
            optionValuesToUpdate: $optionValuesToUpdate
            optionValuesToDelete: $optionValuesToDelete
            variantStrategy: LEAVE_AS_IS
          ) {
            product { id }
            userErrors { field message code }
          }
        }
        GQL;

        $res = $this->gql($shop, $m, [
            'productId' => $productGid,
            'option' => $option,
            'optionValuesToUpdate' => $optionValuesToUpdate,
            'optionValuesToDelete' => $optionValuesToDelete,
        ]);

        if (!empty($res['errors'])) {
            Log::error('productOptionUpdate top-level errors', ['target' => $shop->domain, 'errors' => $res['errors']]);
            throw new \RuntimeException('productOptionUpdate top-level errors: '.json_encode($res['errors']));
        }

        $ue = $res['data']['productOptionUpdate']['userErrors'] ?? [];
        if (!empty($ue)) {
            Log::error('productOptionUpdate userErrors', ['target' => $shop->domain, 'userErrors' => $ue]);
            throw new \RuntimeException('productOptionUpdate userErrors: '.json_encode($ue));
        }
    }

    /** @return array<string, bool> */
    private function fetchTrackedMapBySourceId(Shop $shop, string $productGid): array
    {
        $query = <<<'GQL'
        query SourceVariantTracking($id: ID!) {
          product(id: $id) {
            variants(first: 250) {
              nodes {
                legacyResourceId
                inventoryItem { tracked }
              }
            }
          }
        }
        GQL;

        $response = $this->gql($shop, $query, ['id' => $productGid]);
        $result = [];

        foreach ($response['data']['product']['variants']['nodes'] ?? [] as $variant) {
            $sourceId = (string) ($variant['legacyResourceId'] ?? '');
            if ($sourceId !== '') {
                $result[$sourceId] = (bool) ($variant['inventoryItem']['tracked'] ?? false);
            }
        }

        return $result;
    }

    private function setParentVariantMetafield(Shop $target, string $targetVariantGid, int $sourceVariantId, ?string $matchedBy = null): void
    {
        $mutation = <<<'GQL'
        mutation SetParentVariant($metafields: [MetafieldsSetInput!]!) {
          metafieldsSet(metafields: $metafields) {
            userErrors { field message code }
          }
        }
        GQL;

        $response = $this->gql($target, $mutation, [
            'metafields' => [[
                'ownerId' => $targetVariantGid,
                'namespace' => 'custom',
                'key' => 'parentvariant',
                'type' => 'number_integer',
                'value' => (string)$sourceVariantId,
            ]],
        ]);

        if (!empty($response['errors'])) {
            throw new \RuntimeException('Parentvariant metafield GraphQL errors: '.json_encode($response['errors']));
        }

        $errors = $response['data']['metafieldsSet']['userErrors'] ?? [];
        if ($errors) {
            throw new \RuntimeException('Parentvariant metafield userErrors: '.json_encode($errors));
        }

        Log::info('Parentvariant metafield set on target variant', [
            'target_shop' => $target->domain,
            'target_variant_gid' => $targetVariantGid,
            'source_variant_id' => $sourceVariantId,
            'matched_by' => $matchedBy,
        ]);
    }



    // --- Construiește input pentru BulkUpdate din payload-ul sursă normalizat ---
    private function buildVariantUpdateInput(string $targetVariantGid, array $src): array
    {
        $input = [
            'id' => $targetVariantGid,
        ];

        if (array_key_exists('price', $src) && $src['price'] !== null && $src['price'] !== '') {
            $input['price'] = (string)$src['price'];
        }

        // Important: dacă sursa trimite explicit null, trimitem null ca să ștergem compareAtPrice pe target.
        if (array_key_exists('compare_at_price', $src)) {
            $cap = $src['compare_at_price'];
            $input['compareAtPrice'] = ($cap === null || $cap === '') ? null : (string)$cap;
        }

        if (array_key_exists('taxable', $src) && $src['taxable'] !== null) {
            $input['taxable'] = (bool)$src['taxable'];
        }

        if (array_key_exists('inventory_policy', $src) && $src['inventory_policy'] !== null && $src['inventory_policy'] !== '') {
            $input['inventoryPolicy'] = (strtolower((string)$src['inventory_policy']) === 'continue') ? 'CONTINUE' : 'DENY';
        }

        if (array_key_exists('barcode', $src)) {
            $input['barcode'] = ($src['barcode'] === '' || $src['barcode'] === null)
                ? null
                : (string) $src['barcode'];
        }

        if (array_key_exists('sku', $src)) {
            $input['inventoryItem'] = [
                'sku' => ($src['sku'] === null) ? '' : (string) $src['sku'],
            ];
        }

        // dacă vrei și greutatea (acceptată în bulk), poți păstra:
        // $input['weight'] = $src['weight'] ?? null;
        // $input['weightUnit'] = $this->mapWeightUnit($src['weight_unit'] ?? null);

        return $input;
    }



    // --- Bulk update pe variante ---
    private function productVariantsBulkUpdate(Shop $shop, string $productGid, array $variantInputs): void
    {
        if (empty($variantInputs)) return;

        $m = <<<'GQL'
        mutation productVariantsBulkUpdate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
        productVariantsBulkUpdate(productId: $productId, variants: $variants) {
            product { id }
            userErrors { field message code }
        }
        }
        GQL;

        $res = $this->gql($shop, $m, [
            'productId' => $productGid,
            'variants'  => array_values($variantInputs),
        ]);

        if (!empty($res['errors'])) {
            \Log::error('productVariantsBulkUpdate top-level errors', ['target' => $shop->domain, 'errors' => $res['errors']]);
            throw new \RuntimeException('productVariantsBulkUpdate errors: '.json_encode($res['errors']));
        }
        $ue = $res['data']['productVariantsBulkUpdate']['userErrors'] ?? [];
        if (!empty($ue)) {
            \Log::error('productVariantsBulkUpdate userErrors', ['target' => $shop->domain, 'userErrors' => $ue]);
            throw new \RuntimeException('productVariantsBulkUpdate userErrors: '.json_encode($ue));
        }
    }

    private function mapWeightUnit(?string $u): ?string {
        if (!$u) return null;
        $u = strtolower($u);
        return match ($u) {
            'g', 'gram', 'grams'       => 'GRAMS',
            'kg', 'kilogram', 'kilograms' => 'KILOGRAMS',
            'lb', 'lbs', 'pound', 'pounds' => 'POUNDS',
            'oz', 'ounce', 'ounces'    => 'OUNCES',
            default => null,
        };
    }




    // private function productVariantUpdateOne(Shop $shop, array $variantInput): void
    // {
    //     $m = <<<'GQL'
    //     mutation productVariantUpdate($input: ProductVariantInput!) {
    //     productVariantUpdate(input: $input) {
    //         productVariant { id sku barcode }
    //         userErrors { field message code }
    //     }
    //     }
    //     GQL;

    //     $res = $this->gql($shop, $m, ['input' => $variantInput]);

    //     if (!empty($res['errors'])) {
    //         \Log::error('productVariantUpdate top-level errors', ['target' => $shop->domain, 'errors' => $res['errors']]);
    //         throw new \RuntimeException('productVariantUpdate errors: '.json_encode($res['errors']));
    //     }
    //     $ue = $res['data']['productVariantUpdate']['userErrors'] ?? [];
    //     if (!empty($ue)) {
    //         \Log::error('productVariantUpdate userErrors', ['target' => $shop->domain, 'userErrors' => $ue]);
    //         throw new \RuntimeException('productVariantUpdate userErrors: '.json_encode($ue));
    //     }
    // }



    // private function buildVariantIdentityInput(string $targetVariantGid, array $src): ?array
    // {
    //     $input = array_filter([
    //         'id'      => $targetVariantGid,
    //         'sku'     => array_key_exists('sku', $src) ? (string)$src['sku'] : null,
    //         'barcode' => array_key_exists('barcode', $src) ? (string)$src['barcode'] : null,
    //     ], fn($v) => $v !== null);

    //     // dacă nu avem nimic de actualizat, întoarcem null
    //     return (count($input) > 1) ? $input : null; // >1 fiindcă ‘id’ e mereu prezent
    // }


    private function fetchInventoryItemAndLocations(Shop $shop, string $variantGid): array
    {
        $q = <<<'GQL'
        query($id: ID!) {
        productVariant(id: $id) {
            id
            inventoryItem {
            id
            inventoryLevels(first: 50) {
                nodes {
                location { id }
                }
            }
            }
        }
        }
        GQL;

        $r = $this->gql($shop, $q, ['id' => $variantGid]);

        $itemId = $r['data']['productVariant']['inventoryItem']['id'] ?? null;
        $levels = $r['data']['productVariant']['inventoryItem']['inventoryLevels']['nodes'] ?? [];

        $locIds = [];
        foreach ($levels as $n) {
            $lid = $n['location']['id'] ?? null;
            if ($lid) $locIds[] = $lid;
        }
        return [$itemId, $locIds];
    }

    private function inventorySetQuantities(Shop $shop, string $inventoryItemId, array $locationIds, int $qty): void
    {
        if (!$inventoryItemId || empty($locationIds)) return;

        // Construim set absolut pentru fiecare locație conectată
        $quantities = array_map(fn($locId) => [
            'inventoryItemId' => $inventoryItemId,
            'locationId'      => $locId,
            'quantity'        => $qty,
        ], $locationIds);

        $m = <<<'GQL'
        mutation inventorySetQuantities($input: InventorySetQuantitiesInput!) {
        inventorySetQuantities(input: $input) {
            inventoryAdjustmentGroup {
            id
            }
            userErrors { field message code }
        }
        }
        GQL;

        $input = [
            // conform cerințelor API: reason în lowercase din enum-ul acceptat
            'reason'                => 'correction',
            // setăm cantitatea 'available' (alternativ: 'on_hand')
            'name'                  => 'available',
            // nu trimitem compareQuantity, deci ignorăm comparația
            'ignoreCompareQuantity' => true,
            'quantities'            => $quantities,
        ];

        $res = $this->gql($shop, $m, ['input' => $input]);

        if (!empty($res['errors'])) {
            \Log::error('inventorySetQuantities top-level errors', ['target' => $shop->domain, 'errors' => $res['errors']]);
            throw new \RuntimeException('inventorySetQuantities errors: '.json_encode($res['errors']));
        }
        $ue = $res['data']['inventorySetQuantities']['userErrors'] ?? [];
        if (!empty($ue)) {
            \Log::error('inventorySetQuantities userErrors', ['target' => $shop->domain, 'userErrors' => $ue]);
            throw new \RuntimeException('inventorySetQuantities userErrors: '.json_encode($ue));
        }
    }

    private function inventoryItemUpdate(Shop $shop, string $inventoryItemId, bool $tracked): void
    {
        $m = <<<'GQL'
        mutation inventoryItemUpdate($id: ID!, $tracked: Boolean!) {
          inventoryItemUpdate(id: $id, input: { tracked: $tracked }) {
            inventoryItem { id tracked }
            userErrors { field message }
          }
        }
        GQL;

        $vars = [
            'id'      => $inventoryItemId,
            'tracked' => $tracked,
        ];

        // \Log::debug('inventoryItemUpdate call', [
        //     'target'  => $shop->domain,
        //     'item_id' => $inventoryItemId,
        //     'tracked' => $tracked,
        // ]);

        $res = $this->gql($shop, $m, $vars);

        if (!empty($res['errors'])) {
            \Log::error('inventoryItemUpdate top-level errors', ['target' => $shop->domain, 'errors' => $res['errors']]);
            throw new \RuntimeException('inventoryItemUpdate errors: '.json_encode($res['errors']));
        }
        $ue = $res['data']['inventoryItemUpdate']['userErrors'] ?? [];
        if (!empty($ue)) {
            \Log::error('inventoryItemUpdate userErrors', ['target' => $shop->domain, 'userErrors' => $ue]);
            throw new \RuntimeException('inventoryItemUpdate userErrors: '.json_encode($ue));
        }

        // \Log::debug('inventoryItemUpdate OK', [
        //     'target'  => $shop->domain,
        //     'item_id' => $inventoryItemId,
        //     'tracked' => $tracked,
        // ]);
    }

    // Actualizează o variantă individuală (ex. pentru inventoryManagement)
    private function productVariantUpdateOne(Shop $shop, array $variantInput): void
    {
        $m = <<<'GQL'
        mutation productVariantUpdate($input: ProductVariantInput!) {
          productVariantUpdate(input: $input) {
            productVariant { id inventoryManagement }
            userErrors { field message }
          }
        }
        GQL;

        \Log::debug('productVariantUpdateOne call', [
            'target' => $shop->domain,
            'fields' => array_keys($variantInput),
        ]);

        $res = $this->gql($shop, $m, ['input' => $variantInput]);

        if (!empty($res['errors'])) {
            \Log::error('productVariantUpdate top-level errors', ['target' => $shop->domain, 'errors' => $res['errors']]);
            throw new \RuntimeException('productVariantUpdate errors: '.json_encode($res['errors']));
        }
        $ue = $res['data']['productVariantUpdate']['userErrors'] ?? [];
        if (!empty($ue)) {
            \Log::error('productVariantUpdate userErrors', ['target' => $shop->domain, 'userErrors' => $ue]);
            throw new \RuntimeException('productVariantUpdate userErrors: '.json_encode($ue));
        }

        \Log::debug('productVariantUpdateOne OK', [
            'target' => $shop->domain,
        ]);
    }

    // Note: productVariantsUpdate is not supported on this API version; we intentionally avoid using it
    // and rely on productVariantsBulkUpdate/productSet/inventoryItemUpdate instead.


// private function updateVariantIdentities(Shop $shop, string $productGid, array $variantInputs): void
// {
//     if (empty($variantInputs)) return;

//     // 3.1) Încercăm pluralul (dacă schema îl are)
//     $pluralMutation = <<<'GQL'
//     mutation productVariantsUpdate($productId: ID!, $variants: [ProductVariantsUpdateInput!]!) {
//       productVariantsUpdate(productId: $productId, variants: $variants) {
//         product { id }
//         userErrors { field message code }
//       }
//     }
//     GQL;

//     try {
//         $res = $this->gql($shop, $pluralMutation, [
//             'productId' => $productGid,
//             'variants'  => array_values($variantInputs),
//         ]);

//         // dacă schema nu cunoaște câmpul / inputul, Shopify va băga asta în top-level errors
//         $top = $res['errors'] ?? [];
//         $undefined = false;
//         foreach ($top as $e) {
//             $msg = strtolower((string)($e['message'] ?? ''));
//             if (str_contains($msg, "doesn't exist on type 'mutation'")
//              || str_contains($msg, "isn't a defined input type")) {
//                 $undefined = true; break;
//             }
//         }

//         if ($undefined) {
//             // fallback pe singular
//             throw new \RuntimeException('plural-missing');
//         }

//         $ue = $res['data']['productVariantsUpdate']['userErrors'] ?? [];
//         if (!empty($ue)) {
//             \Log::error('productVariantsUpdate userErrors', ['target' => $shop->domain, 'userErrors' => $ue]);
//             throw new \RuntimeException('productVariantsUpdate userErrors: '.json_encode($ue));
//         }

//         \Log::info('productVariantsUpdate OK', ['count' => count($variantInputs)]);
//         return;
//     } catch (\Throwable $e) {
//         if ($e instanceof \RuntimeException && $e->getMessage() === 'plural-missing') {
//             \Log::warning('productVariantsUpdate unsupported on this API version; falling back to singular', [
//                 'target' => $shop->domain,
//             ]);
//         } else {
//             // alte erori reale de execuție → mergem oricum pe fallback
//             \Log::warning('productVariantsUpdate failed; falling back to singular', [
//                 'target' => $shop->domain,
//                 'error'  => $e->getMessage(),
//             ]);
//         }
//     }

//     // 3.2) Fallback: singular, pe fiecare variantă
//     foreach ($variantInputs as $vi) {
//         $this->productVariantUpdateOne($shop, $vi);
//     }
//     \Log::info('productVariantUpdate (fallback) OK', ['count' => count($variantInputs)]);
// }

private function productSetUpdateVariants(Shop $shop, string $productGid, array $variantSets): void
{
    if (empty($variantSets)) return;

    $m = <<<'GQL'
    mutation productSet($input: ProductSetInput!) {
      productSet(input: $input) {
        product { id }
        userErrors { field message code }
      }
    }
    GQL;

    // $variantSets: fiecare element e un ProductVariantSetInput parțial: ['id'=>..., 'sku'=>..., 'barcode'=>..., 'inventoryQuantities'=>[...]].
    $vars = [
        'input' => [
            'id'       => $productGid,
            'variants' => array_values($variantSets),
        ],
    ];

    $res = $this->gql($shop, $m, $vars);

    if (!empty($res['errors'])) {
        \Log::error('productSet top-level errors', ['target' => $shop->domain, 'errors' => $res['errors']]);
        throw new \RuntimeException('productSet errors: '.json_encode($res['errors']));
    }
    $ue = $res['data']['productSet']['userErrors'] ?? [];
    if (!empty($ue)) {
        \Log::error('productSet userErrors', ['target' => $shop->domain, 'userErrors' => $ue]);
        throw new \RuntimeException('productSet userErrors: '.json_encode($ue));
    }
}

// Construieste obiectul pentru ProductSetInput.variants[] (doar dacă avem ceva de setat la sku/barcode)
private function buildVariantSetIdentityInput(string $targetVariantGid, array $src, string $rawKey, array $srcOptions): ?array
{
    $hasSku     = array_key_exists('sku', $src);
    $hasBarcode = array_key_exists('barcode', $src);

    if (!$hasSku && !$hasBarcode) {
        return null; // nimic de setat
    }

    $variant = [
        'id'           => $targetVariantGid,
        'optionValues' => $this->buildOptionValuesForProductSet($rawKey, $srcOptions),
    ];

    if ($hasSku)     { $variant['sku']     = ($src['sku'] ?? null) === null ? null : (string)$src['sku']; }
    if ($hasBarcode) { $variant['barcode'] = ($src['barcode'] ?? null) === null ? null : (string)$src['barcode']; }

    // Notă: dacă vrei să golești barcode, lasă '' (string gol) – Shopify îl “șterge”.
    return $variant;
}


// Din "marime=s|carcasa=plastic" + lista de opțiuni din payload,
// construim [{"name":"Marime","value":"S"},{"name":"Carcasa","value":"Plastic"}]
private function buildOptionValuesForProductSet(string $rawKey, array $srcOptions): array
{
    $pairs = array_filter(explode('|', $rawKey));
    $byCanonName = [];
    foreach ($srcOptions as $opt) {
        $byCanonName[$this->canonOptName($opt['name'])] = $opt;
    }

    $result = [];
    foreach ($pairs as $p) {
        [$n, $v] = array_pad(explode('=', $p, 2), 2, '');
        $nCanon = $this->canonOptName($n);
        $vCanon = $this->canonOptVal($v);

        $origName = $byCanonName[$nCanon]['name'] ?? $n; // păstrăm capitalizarea originală a numelui
        // încearcă să găsești valoarea cu capitalizarea originală din lista de valori a opțiunii
        $origVal = $v;
        if (!empty($byCanonName[$nCanon]['values'])) {
            foreach ($byCanonName[$nCanon]['values'] as $candidate) {
                if ($this->canonOptVal((string)$candidate) === $vCanon) {
                    $origVal = (string)$candidate;
                    break;
                }
            }
        }

        // For productSet, VariantOptionValueInput expects { optionName, name }
        $result[] = ['optionName' => $origName, 'name' => $origVal];
    }
    return $result;
}

// private function fetchVariantOptionValuesForProduct(Shop $shop, string $productGid): array
// {
//     $q = <<<'GQL'
//     query($id: ID!) {
//       product(id: $id) {
//         variants(first: 250) {
//           nodes {
//             id
//             selectedOptions { name value }
//           }
//         }
//       }
//     }
//     GQL;

//     $r = $this->gql($shop, $q, ['id' => $productGid]);
//     $nodes = $r['data']['product']['variants']['nodes'] ?? [];

//     $map = [];
//     foreach ($nodes as $v) {
//         $gid = $v['id'] ?? null;
//         if (!$gid) continue;
//         $ov = [];
//         foreach ($v['selectedOptions'] ?? [] as $so) {
//             $optName = (string)($so['name'] ?? '');
//             $valName = (string)($so['value'] ?? '');
//             if ($optName === '') continue;
//             $ov[] = ['optionName' => $optName, 'name' => $valName]; // <- schema corectă
//         }
//         $map[$gid] = $ov;
//     }
//     return $map; // variantGid => [ { optionName, name }, ... ]
// }

private function productSet(Shop $shop, array $input): void
{
    $m = <<<'GQL'
    mutation productSet($input: ProductSetInput!) {
      productSet(input: $input) {
        product { id }
        userErrors { field message code }
      }
    }
    GQL;

    $res = $this->gql($shop, $m, ['input' => $input]);

    if (!empty($res['errors'])) {
        \Log::error('productSet top-level errors', ['target' => $shop->domain, 'errors' => $res['errors']]);
        throw new \RuntimeException('productSet errors: ' . json_encode($res['errors']));
    }
    $ue = $res['data']['productSet']['userErrors'] ?? [];
    if (!empty($ue)) {
        \Log::error('productSet userErrors', ['target' => $shop->domain, 'userErrors' => $ue]);
        throw new \RuntimeException('productSet userErrors: ' . json_encode($ue));
    }
}


    // Normalizează nume/opțiuni ca să construim chei stabile
    private function canonOptName(string $s): string { return mb_strtolower(trim($s)); }
    private function canonOptVal(string $s): string { return mb_strtolower(trim($s)); }
    private function canonKey(string $k): string    { return mb_strtolower(trim($k)); }


    // === Variants (de activat în pasul următor) ===
    // private function syncVariants(Shop $shop, ProductMirror $pm, array $payload): void { /* toCreate/toUpdate/toDelete + stock */ }
}
