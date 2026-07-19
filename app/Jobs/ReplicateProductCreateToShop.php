<?php

namespace App\Jobs;

use App\Models\ProductMirror;
use App\Models\VariantMirror;
use App\Models\Shop;
use App\Models\SourceProductDeletion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Services\Shopify\ProductImagesBackupService;
use App\Services\Shopify\ShopifyParentIdentityResolver;
use App\Jobs\BemApplyProductWatermark;
use App\Services\Shopify\BemWatermark\BemBackupProductImageResolver;
use App\Services\Shopify\BemWatermark\BemImageIdentityService;
use App\Services\Shopify\BemWatermark\BemSourceCreateMediaResolver;
use App\Services\Shopify\BemWatermark\BemProductWatermarkMetafieldService;
use App\Services\Shopify\BemWatermark\BemShopifyStagedUploadService;
use App\Services\Shopify\BemWatermark\BemWatermarkEligibilityService;
use App\Services\Shopify\BemWatermark\BemWatermarkImageProcessor;

class ReplicateProductCreateToShop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 40;
    public $backoff = [10, 30, 60, 120];
    public $timeout = 840;
    public $failOnTimeout = true;

    /**
     * Manual collection IDs per target shop domain.
     * TODO: move to config if the list grows/changing frequently.
     */
    private array $manualCollectionMap = [
        'lustreled.myshopify.com'      => 'gid://shopify/Collection/622468399449',
        'powerleds-ro.myshopify.com'   => 'gid://shopify/Collection/624000663891',
        'eiluminatbackup.myshopify.com'   =>   'gid://shopify/Collection/631556800884',
    ];

    public function __construct(
        public int $targetShopId,
        public int $sourceShopId,
        public int $sourceProductId,
        public array $payload
    ) {}

    public function handle(ShopifyParentIdentityResolver $identityResolver): void
    {
        $bemTempPaths = [];
        $creationLock = null;

        try {
            if (SourceProductDeletion::existsFor($this->sourceShopId, $this->sourceProductId)) {
                Log::warning('ReplicateProductCreate skipped: source product was deleted', [
                    'source_shop_id' => $this->sourceShopId,
                    'source_product_id' => $this->sourceProductId,
                    'target_shop_id' => $this->targetShopId,
                ]);
                return;
            }

            $target = Shop::findOrFail($this->targetShopId);

            if ((int)$target->id === 8 || $target->domain === 'eiluminat-bg.myshopify.com') {
                Log::info('ReplicateProductCreate skipped for BG store', [
                    'target_shop_id' => $target->id,
                    'target_shop_domain' => $target->domain,
                    'source_product_id' => $this->sourceProductId,
                ]);
                return;
            }

            // A create webhook can be delivered more than once. Serialize each
            // source-to-target creation so a retry cannot create a second copy.
            $creationLock = Cache::store('database')->lock(
                "product-replication-create:{$this->sourceShopId}:{$this->sourceProductId}:{$target->id}",
                900
            );

            if (!$creationLock->get()) {
                Log::info('ReplicateProductCreate delayed: creation already in progress', [
                    'source_shop_id' => $this->sourceShopId,
                    'source_product_id' => $this->sourceProductId,
                    'target_shop_id' => $target->id,
                    'target_shop' => $target->domain,
                ]);

                $this->release(15);
                return;
            }

            Log::info('ReplicateProductCreate target shop debug', [
                'target_shop_id'   => $this->targetShopId,
                'target_shop_name' => $target->name ?? null,
                'target_shop_domain' => $target->domain ?? null,
            ]);

            $existingMirror = $this->existingCreatedMirror($target);
            $existingResolution = $identityResolver->resolveProduct(
                $target,
                $this->sourceProductId,
                $existingMirror?->target_product_gid
            );

            if ($existingResolution['status'] === 'ambiguous') {
                Log::error('ReplicateProductCreate stopped: multiple products share parentproduct', [
                    'source_shop_id' => $this->sourceShopId,
                    'source_product_id' => $this->sourceProductId,
                    'target_shop_id' => $target->id,
                    'target_shop' => $target->domain,
                    'candidate_gids' => array_column($existingResolution['candidates'], 'id'),
                ]);

                return;
            }

            if ($existingResolution['status'] === 'found') {
                $product = $existingResolution['product'];
                $mirror = $this->storeExistingProductMirror($target, [
                    'id' => isset($product['legacyResourceId']) ? (int) $product['legacyResourceId'] : null,
                    'gid' => $product['id'],
                ]);

                Log::warning('ReplicateProductCreate skipped: strict parentproduct already exists', [
                    'source_shop_id' => $this->sourceShopId,
                    'source_product_id' => $this->sourceProductId,
                    'target_shop_id' => $target->id,
                    'target_shop' => $target->domain,
                    'target_product_id' => $mirror->target_product_id,
                    'target_product_gid' => $mirror->target_product_gid,
                ]);

                $this->setParentProductMetafield($target, $mirror->target_product_gid);
                if (!$this->continueExistingCreate($identityResolver, $target, $mirror)) {
                    return;
                }

                ReplicateProductUpdateToShop::dispatch(
                    $this->targetShopId,
                    $this->sourceShopId,
                    $this->sourceProductId,
                    $this->payload
                )->onQueue('replication');
                $this->dispatchBemWatermarkIfEligible(
                    $target,
                    $mirror->target_product_gid,
                    $mirror->target_product_id ? (int) $mirror->target_product_id : null
                );

                return;
            }

            if ($existingMirror) {
                Log::error('ReplicateProductCreate stopped: local mirror failed strict parentproduct validation', [
                    'source_shop_id' => $this->sourceShopId,
                    'source_product_id' => $this->sourceProductId,
                    'target_shop_id' => $target->id,
                    'target_shop' => $target->domain,
                    'cached_target_product_id' => $existingMirror->target_product_id,
                    'cached_target_product_gid' => $existingMirror->target_product_gid,
                ]);

                return;
            }

            if ($this->hydrateDelayedBemSourceMediaBeforeCreate($target)) {
                return;
            }

            $metaDescription = $this->fetchSourceMetaDescription();
            $bemCreate = $this->prepareBemWatermarkedCreate($target);
            if (($bemCreate['released'] ?? false) === true) {
                return;
            }

            $bemTempPaths = $bemCreate['temp_paths'] ?? [];
            $basePayloadForCreate = $this->payloadWithCleanImagesForBackupCreate($target, $this->payload);
            $payloadForCreate = $bemCreate
                ? $this->payloadWithBemWatermarkedImages($this->payload, $bemCreate['images'])
                : $basePayloadForCreate;

            // The delete webhook may have arrived while BEM media was being prepared.
            if (SourceProductDeletion::existsFor($this->sourceShopId, $this->sourceProductId)) {
                Log::warning('ReplicateProductCreate skipped before Shopify create: source product was deleted', [
                    'source_shop_id' => $this->sourceShopId,
                    'source_product_id' => $this->sourceProductId,
                    'target_shop_id' => $this->targetShopId,
                ]);
                return;
            }

            // BEM processing can take time. Check again immediately before the
            // write in case another execution created the target meanwhile.
            $preCreateResolution = $identityResolver->resolveProduct($target, $this->sourceProductId);
            if ($preCreateResolution['status'] !== 'missing') {
                if ($preCreateResolution['status'] === 'found') {
                    $product = $preCreateResolution['product'];
                    $mirror = $this->storeExistingProductMirror($target, [
                        'id' => isset($product['legacyResourceId']) ? (int) $product['legacyResourceId'] : null,
                        'gid' => $product['id'],
                    ]);

                    $this->setParentProductMetafield($target, $mirror->target_product_gid);
                    if ($this->continueExistingCreate($identityResolver, $target, $mirror)) {
                        ReplicateProductUpdateToShop::dispatch(
                            $this->targetShopId,
                            $this->sourceShopId,
                            $this->sourceProductId,
                            $this->payload
                        )->onQueue('replication');
                        $this->dispatchBemWatermarkIfEligible(
                            $target,
                            $mirror->target_product_gid,
                            $mirror->target_product_id ? (int) $mirror->target_product_id : null
                        );
                    }
                }

                Log::warning('ReplicateProductCreate skipped before Shopify create: parentproduct already exists', [
                    'source_shop_id' => $this->sourceShopId,
                    'source_product_id' => $this->sourceProductId,
                    'target_shop_id' => $target->id,
                    'target_shop' => $target->domain,
                    'status' => $preCreateResolution['status'],
                    'candidate_gids' => array_column($preCreateResolution['candidates'], 'id'),
                ]);

                return;
            }

            [$productGid, $productLegacyId, $variantMap] = $this->productCreate($target, $payloadForCreate, $metaDescription);
            $pm = $this->storeExistingProductMirror($target, [
                'id' => $productLegacyId,
                'gid' => $productGid,
            ]);
            $this->setParentProductMetafield($target, $productGid);

            $this->attachProductToManualCollection($target, $productGid);

            $mirror = ProductMirror::where([
                'source_shop_id'    => $this->sourceShopId,
                'source_product_id' => $this->sourceProductId,
                'target_shop_id'    => $target->id,
            ])->first();

            $imgs = $this->extractSourceImages($payloadForCreate);

            if ($mirror) {
                $snap = is_array($mirror->last_snapshot ?? null)
                    ? $mirror->last_snapshot
                    : (is_string($mirror->last_snapshot) ? (json_decode($mirror->last_snapshot, true) ?: []) : []);

                $snap['images'] = $imgs;
                $snap['images_fingerprint'] = $this->fingerprintImages($imgs);

                $mirror->last_snapshot = $snap;
                $mirror->save();
            }

            // Persist backup of images metadata into the target shop metafield
            ProductImagesBackupService::syncFromImages($target, $productGid, $imgs);

            if ($bemCreate) {
                $this->finalizeBemWatermarkedCreate($target, $productGid, $productLegacyId, $bemCreate);
            } else {
                $this->dispatchBemWatermarkIfEligible($target, $productGid, $productLegacyId);
            }

            $this->persistVariantMappings($target, $pm, $variantMap);

            // Log::info('Replicated product to target shop', [
            //     'target'            => $target->domain,
            //     'target_gid'        => $productGid,
            //     'target_id'         => $productLegacyId,
            //     'source_product_id' => $this->sourceProductId,
            //     'variants_mapped'   => count($variantMap ?? []),
            // ]);

            if ($this->statusFromPayload($this->payload) === 'ACTIVE') {
                try {
                    $this->publishProductToAllChannels($target, $productGid);
                } catch (\Throwable $e) {
                    Log::warning('Publish to channels failed (non-fatal)', [
                        'target' => $target->domain,
                        'productGid' => $productGid,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                Log::info('Publish to channels skipped for non-active product', [
                    'target' => $target->domain,
                    'productGid' => $productGid,
                    'status' => $this->statusFromPayload($this->payload),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Product replication failed', [
                'source_shop_id'    => $this->sourceShopId,
                'target_shop_id'    => $this->targetShopId,
                'source_product_id' => $this->sourceProductId,
                'message'           => $e->getMessage(),
            ]);

            try {
                Mail::raw(
                    "Product replication failed for source product {$this->sourceProductId} (source shop {$this->sourceShopId}, target shop {$this->targetShopId}).\nError: {$e->getMessage()}",
                    function ($message) {
                        $message->to('mitnickoff121@gmail.com')
                            ->subject('Product replication failed');
                    }
                );
            } catch (\Throwable $mailException) {
                Log::error('Failed to send replication failure notification', [
                    'error' => $mailException->getMessage(),
                ]);
            }

            throw $e;
        } finally {
            optional($creationLock)->release();

            if (!empty($bemTempPaths)) {
                try {
                    app(BemWatermarkImageProcessor::class)->cleanup($bemTempPaths);
                } catch (\Throwable $cleanupException) {
                    Log::warning('BEM watermark temp cleanup failed', [
                        'source_shop_id' => $this->sourceShopId,
                        'target_shop_id' => $this->targetShopId,
                        'source_product_id' => $this->sourceProductId,
                        'error' => $cleanupException->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Lightweight GraphQL caller.
     */
    private function gql(Shop $shop, string $query, array $variables = []): array
    {
        $version  = $shop->api_version ?: '2025-01';
        $endpoint = "https://{$shop->domain}/admin/api/{$version}/graphql.json";

        $payload = ['query' => $query];
        if (!empty($variables)) {
            $payload['variables'] = $variables;
        }

        $resp = Http::withHeaders([
            'X-Shopify-Access-Token' => $shop->access_token,
            'Content-Type'           => 'application/json',
        ])->post($endpoint, $payload);

        $resp->throw();
        return $resp->json();
    }

    private function existingCreatedMirror(Shop $target): ?ProductMirror
    {
        return ProductMirror::where([
            'source_shop_id' => $this->sourceShopId,
            'source_product_id' => $this->sourceProductId,
            'target_shop_id' => $target->id,
        ])
            ->whereNotNull('target_product_gid')
            ->first();
    }

    /** @param array{id: int|null, gid: string} $product */
    private function storeExistingProductMirror(Shop $target, array $product): ProductMirror
    {
        return ProductMirror::updateOrCreate(
            [
                'source_shop_id' => $this->sourceShopId,
                'source_product_id' => $this->sourceProductId,
                'target_shop_id' => $target->id,
            ],
            [
                'source_product_gid' => "gid://shopify/Product/{$this->sourceProductId}",
                'target_product_gid' => $product['gid'],
                'target_product_id' => $product['id'],
            ]
        );
    }

    private function continueExistingCreate(
        ShopifyParentIdentityResolver $identityResolver,
        Shop $target,
        ProductMirror $mirror
    ): bool {
        try {
            $sourceVariantsById = $this->sourceVariantsById($this->payload);
        } catch (\RuntimeException $e) {
            Log::error('ReplicateProductCreate continuation stopped: invalid source variants', [
                'source_product_id' => $this->sourceProductId,
                'target_shop' => $target->domain,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $state = $identityResolver->targetVariantState($target, $mirror->target_product_gid);
        if (!empty($state['ambiguous_parent_ids'])) {
            Log::error('ReplicateProductCreate continuation stopped: ambiguous parentvariant values', [
                'source_product_id' => $this->sourceProductId,
                'target_shop' => $target->domain,
                'ambiguous_parentvariant_ids' => array_keys($state['ambiguous_parent_ids']),
            ]);

            return false;
        }

        if (!empty($state['unmanaged_gids'])) {
            $localMirrors = VariantMirror::where('product_mirror_id', $mirror->id)
                ->whereNotNull('target_variant_gid')
                ->get()
                ->keyBy('target_variant_gid');

            foreach ($state['unmanaged_gids'] as $targetVariantGid) {
                $localMirror = $localMirrors->get($targetVariantGid);
                $sourceVariantId = (int) ($localMirror?->source_variant_id ?? 0);
                if ($sourceVariantId <= 0 || !isset($sourceVariantsById[(string) $sourceVariantId])) {
                    continue;
                }

                $this->setParentVariantMetafield($target, $targetVariantGid, $sourceVariantId);
            }

            $state = $identityResolver->targetVariantState($target, $mirror->target_product_gid);
        }

        $hasMultipleVariants = count($sourceVariantsById) > 1;
        $hasProductOptions = !empty($this->payload['options']);
        $isUntouchedMultiVariantShell = count($state['unmanaged_gids']) === 1
            && empty($state['by_parent_id'])
            && empty($state['ambiguous_parent_ids'])
            && ($hasMultipleVariants || $hasProductOptions);

        if ($isUntouchedMultiVariantShell) {
            $variantMap = $this->createAllOptionsAndVariants(
                shop: $target,
                productGid: $mirror->target_product_gid,
                src: $this->payload,
                locationLegacyId: $target->location_legacy_id ?? null
            );
            $this->persistVariantMappings($target, $mirror, $variantMap);
            $state = $identityResolver->targetVariantState($target, $mirror->target_product_gid);
        }

        if (!empty($state['unmanaged_gids']) || !empty($state['ambiguous_parent_ids'])) {
            Log::error('ReplicateProductCreate continuation stopped: variant identity cannot be proven', [
                'source_product_id' => $this->sourceProductId,
                'target_shop' => $target->domain,
                'unmanaged_variant_gids' => $state['unmanaged_gids'],
                'ambiguous_parentvariant_ids' => array_keys($state['ambiguous_parent_ids']),
            ]);

            return false;
        }

        $variantMap = [];
        foreach ($state['by_parent_id'] as $sourceVariantId => $targetNode) {
            $sourceVariant = $sourceVariantsById[(string) $sourceVariantId] ?? null;
            if (!$sourceVariant || empty($targetNode['id'])) {
                continue;
            }

            $optionNames = array_map(
                static fn (array $option): string => (string) ($option['name'] ?? ''),
                $this->payload['options'] ?? []
            );
            $variantMap[] = [
                'source_variant_id' => (int) $sourceVariantId,
                'source_options_key' => $this->buildOptionsKeyFromSource($sourceVariant, $optionNames),
                'target_variant_gid' => $targetNode['id'],
                'target_variant_id' => isset($targetNode['legacyResourceId'])
                    ? (int) $targetNode['legacyResourceId']
                    : $this->legacyIdFromGid($targetNode['id']),
                'inventory_item_gid' => null,
                'snapshot' => [
                    'price' => $sourceVariant['price'] ?? null,
                    'sku' => $sourceVariant['sku'] ?? null,
                    'barcode' => $sourceVariant['barcode'] ?? null,
                    'qty' => $sourceVariant['inventory_quantity'] ?? null,
                ],
            ];
        }
        $this->persistVariantMappings($target, $mirror, $variantMap);

        return true;
    }

    private function persistVariantMappings(Shop $target, ProductMirror $mirror, array $variantMap): void
    {
        foreach ($variantMap as $mapping) {
            $sourceVariantId = (int) ($mapping['source_variant_id'] ?? 0);
            $targetVariantGid = (string) ($mapping['target_variant_gid'] ?? '');
            if ($sourceVariantId <= 0 || $targetVariantGid === '') {
                throw new \RuntimeException('Cannot persist variant mapping without deterministic parent IDs');
            }

            $snapshot = $mapping['snapshot'] ?? [];
            VariantMirror::updateOrCreate(
                [
                    'product_mirror_id' => $mirror->id,
                    'source_variant_id' => $sourceVariantId,
                ],
                [
                    'source_options_key' => $mapping['source_options_key'] ?? '',
                    'target_variant_gid' => $targetVariantGid,
                    'target_variant_id' => $mapping['target_variant_id'] ?? $this->legacyIdFromGid($targetVariantGid),
                    'inventory_item_gid' => $mapping['inventory_item_gid'] ?? null,
                    'last_snapshot' => $snapshot,
                    'variant_fingerprint' => hash('sha256', json_encode([
                        'price' => $snapshot['price'] ?? null,
                        'sku' => $snapshot['sku'] ?? null,
                        'barcode' => $snapshot['barcode'] ?? null,
                    ])),
                    'inventory_fingerprint' => hash('sha256', json_encode([
                        'qty' => $snapshot['qty'] ?? null,
                    ])),
                ]
            );

            $this->setParentVariantMetafield($target, $targetVariantGid, $sourceVariantId);
        }
    }

    private function persistProvisionalSingleVariantMapping(
        ProductMirror $mirror,
        array $sourceVariant,
        array $targetVariant
    ): void {
        $sourceVariantId = (int) ($sourceVariant['id'] ?? 0);
        $targetVariantGid = (string) ($targetVariant['id'] ?? '');
        if ($sourceVariantId <= 0 || $targetVariantGid === '') {
            throw new \RuntimeException('Product create did not return deterministic single variant IDs');
        }

        $optionNames = array_map(
            static fn (array $option): string => (string) ($option['name'] ?? ''),
            $this->payload['options'] ?? []
        );
        VariantMirror::updateOrCreate(
            [
                'product_mirror_id' => $mirror->id,
                'source_variant_id' => $sourceVariantId,
            ],
            [
                'source_options_key' => $this->buildOptionsKeyFromSource($sourceVariant, $optionNames),
                'target_variant_gid' => $targetVariantGid,
                'target_variant_id' => isset($targetVariant['legacyResourceId'])
                    ? (int) $targetVariant['legacyResourceId']
                    : $this->legacyIdFromGid($targetVariantGid),
                'inventory_item_gid' => $targetVariant['inventoryItem']['id'] ?? null,
                'last_snapshot' => [],
            ]
        );
    }

    /** @return array<string, array> */
    private function sourceVariantsById(array $payload): array
    {
        $variants = array_values($payload['variants'] ?? []);
        if (empty($variants)) {
            throw new \RuntimeException('Create payload contains no source variants');
        }

        $byId = [];
        foreach ($variants as $variant) {
            $sourceVariantId = (int) ($variant['id'] ?? 0);
            if ($sourceVariantId <= 0) {
                throw new \RuntimeException('Create payload contains a source variant without source variant ID');
            }
            if (isset($byId[(string) $sourceVariantId])) {
                throw new \RuntimeException('Create payload contains duplicate source variant ID '.$sourceVariantId);
            }

            $byId[(string) $sourceVariantId] = $variant;
        }

        return $byId;
    }

    private function dispatchBemWatermarkIfEligible(Shop $target, string $productGid, ?int $productLegacyId): void
    {
        try {
            $eligibility = app(BemWatermarkEligibilityService::class);
            if (!$eligibility->isEligiblePayloadForTarget($this->payload, $target)) {
                return;
            }

            if (count($this->extractSourceImages($this->payload)) === 0) {
                Log::info('BEM watermark dispatch skipped: source create payload has no media', [
                    'target_shop' => $target->domain,
                    'source_shop_id' => $this->sourceShopId,
                    'source_product_id' => $this->sourceProductId,
                    'target_product_gid' => $productGid,
                ]);

                return;
            }

            BemApplyProductWatermark::dispatch(
                targetShopId: $target->id,
                sourceShopId: $this->sourceShopId,
                sourceProductId: $this->sourceProductId,
                targetProductGid: $productGid,
                targetProductId: $productLegacyId,
                title: (string) ($this->payload['title'] ?? 'product'),
                sourcePayload: $this->payload
            )->onQueue('watermarks');

            Log::info('BEM watermark job queued', [
                'target_shop' => $target->domain,
                'source_product_id' => $this->sourceProductId,
                'target_product_gid' => $productGid,
            ]);
        } catch (\Throwable $e) {
            Log::error('BEM watermark dispatch failed', [
                'target_shop' => $target->domain,
                'source_product_id' => $this->sourceProductId,
                'target_product_gid' => $productGid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function prepareBemWatermarkedCreate(Shop $target): ?array
    {
        $eligibility = app(BemWatermarkEligibilityService::class);
        if (!$eligibility->isEligiblePayloadForTarget($this->payload, $target)) {
            return null;
        }

        if ($eligibility->isDryRun()) {
            return null;
        }

        if (count($this->extractSourceImages($this->payload)) === 0) {
            Log::info('BEM direct create skipped: source create payload has no media', [
                'target_shop' => $target->domain,
                'source_shop_id' => $this->sourceShopId,
                'source_product_id' => $this->sourceProductId,
            ]);

            return null;
        }

        $backupImages = app(BemBackupProductImageResolver::class)->resolve(
            $this->sourceShopId,
            $this->sourceProductId
        );

        if (!$backupImages->ready) {
            Log::warning('BEM direct create waiting for backup product', [
                'target_shop' => $target->domain,
                'source_shop_id' => $this->sourceShopId,
                'source_product_id' => $this->sourceProductId,
                'attempt' => $this->attempts(),
                'reason' => $backupImages->reason,
            ]);

            if ($this->attempts() >= $this->tries) {
                throw new \RuntimeException('BEM direct create backup product not ready: '.($backupImages->reason ?: 'unknown'));
            }

            $this->release(60);
            return ['released' => true, 'temp_paths' => []];
        }

        $imageProcessor = app(BemWatermarkImageProcessor::class);
        $processedResult = $imageProcessor->process(
            $target,
            (string) ($this->payload['title'] ?? 'product'),
            $backupImages->images
        );

        $uploadedImages = app(BemShopifyStagedUploadService::class)->uploadProcessedImagesForProductCreate(
            $target,
            $processedResult['processed']
        );

        Log::info('BEM direct create watermarked images prepared', [
            'target_shop' => $target->domain,
            'source_product_id' => $this->sourceProductId,
            'images_count' => count($uploadedImages),
        ]);

        return [
            'backup_shop' => $backupImages->backupShop,
            'backup_source_product_id' => $backupImages->sourceProductId,
            'backup_source_product_gid' => $backupImages->sourceProductGid,
            'images' => $uploadedImages,
            'temp_paths' => $processedResult['temp_paths'],
        ];
    }

    private function hydrateDelayedBemSourceMediaBeforeCreate(Shop $target): bool
    {
        if (count($this->extractSourceImages($this->payload)) > 0) {
            return false;
        }

        $source = Shop::find($this->sourceShopId);
        $eligibility = app(BemWatermarkEligibilityService::class);
        if (!$source
            || $eligibility->isDryRun()
            || !$eligibility->isEligiblePayloadForSource($this->payload, $source)
        ) {
            return false;
        }

        $result = app(BemSourceCreateMediaResolver::class)->resolve(
            $source,
            'gid://shopify/Product/'.$this->sourceProductId
        );

        if ($result['status'] === 'ready') {
            $this->payload['images'] = $result['images'];

            Log::notice('BEM create hydrated delayed source media from Shopify', [
                'source_shop' => $source->domain,
                'source_product_id' => $this->sourceProductId,
                'target_shop' => $target->domain,
                'images_count' => count($result['images']),
            ]);

            return false;
        }

        if ($result['status'] === 'processing' && $this->attempts() >= $this->tries) {
            throw new \RuntimeException('BEM source media remained PROCESSING during target create retry window');
        }

        $withinEmptyMediaGrace = $result['status'] === 'empty' && $this->attempts() < 10;
        if ($result['status'] === 'processing' || $withinEmptyMediaGrace) {
            Log::warning('BEM create waiting for source media before target creation', [
                'source_shop' => $source->domain,
                'source_product_id' => $this->sourceProductId,
                'target_shop' => $target->domain,
                'media_status' => $result['status'],
                'attempt' => $this->attempts(),
            ]);

            $this->release(15);
            return true;
        }

        Log::info('BEM create source has no media after grace period', [
            'source_shop' => $source->domain,
            'source_product_id' => $this->sourceProductId,
            'target_shop' => $target->domain,
            'attempt' => $this->attempts(),
        ]);

        return false;
    }

    private function setParentProductMetafield(Shop $target, string $productGid): void
    {
        $mutation = <<<'GQL'
        mutation SetParentProduct($metafields: [MetafieldsSetInput!]!) {
          metafieldsSet(metafields: $metafields) {
            userErrors { field message code }
          }
        }
        GQL;

        $response = $this->gql($target, $mutation, [
            'metafields' => [[
                'ownerId' => $productGid,
                'namespace' => 'custom',
                'key' => 'parentproduct',
                'type' => 'number_integer',
                'value' => (string) $this->sourceProductId,
            ]],
        ]);

        $topLevelErrors = $response['errors'] ?? [];
        $userErrors = $response['data']['metafieldsSet']['userErrors'] ?? [];
        if (!empty($topLevelErrors) || !empty($userErrors)) {
            Log::error('Parentproduct metafield set failed on created product', [
                'target_shop' => $target->domain,
                'target_product_gid' => $productGid,
                'source_product_id' => $this->sourceProductId,
                'errors' => $topLevelErrors,
                'user_errors' => $userErrors,
            ]);

            throw new \RuntimeException('Parentproduct metafield write failed: '.json_encode([
                'errors' => $topLevelErrors,
                'user_errors' => $userErrors,
            ]));
        }

        Log::info('Parentproduct metafield set on created product', [
            'target_shop' => $target->domain,
            'target_product_gid' => $productGid,
            'source_product_id' => $this->sourceProductId,
        ]);
    }

    private function setParentVariantMetafield(Shop $target, string $targetVariantGid, int $sourceVariantId): void
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
                'value' => (string) $sourceVariantId,
            ]],
        ]);

        $topLevelErrors = $response['errors'] ?? [];
        $userErrors = $response['data']['metafieldsSet']['userErrors'] ?? [];
        if (!empty($topLevelErrors) || !empty($userErrors)) {
            Log::error('Parentvariant metafield set failed on created variant', [
                'target_shop' => $target->domain,
                'target_variant_gid' => $targetVariantGid,
                'source_variant_id' => $sourceVariantId,
                'errors' => $topLevelErrors,
                'user_errors' => $userErrors,
            ]);

            throw new \RuntimeException('Parentvariant metafield write failed: '.json_encode([
                'errors' => $topLevelErrors,
                'user_errors' => $userErrors,
            ]));
        }

        Log::info('Parentvariant metafield set on created variant', [
            'target_shop' => $target->domain,
            'target_variant_gid' => $targetVariantGid,
            'source_variant_id' => $sourceVariantId,
        ]);
    }

    private function payloadWithCleanImagesForBackupCreate(Shop $target, array $payload): array
    {
        $backupDomain = strtolower((string) config('features.bem_watermark_sync.backup_shop_domain'));
        if ($backupDomain === '' || strtolower((string) $target->domain) !== $backupDomain) {
            return $payload;
        }

        $identity = app(BemImageIdentityService::class);
        $images = $this->extractSourceImages($payload);
        $hasWatermarkedImages = false;
        foreach ($images as $image) {
            if ($identity->isWatermarkedUrl($image['src'] ?? null)) {
                $hasWatermarkedImages = true;
                break;
            }
        }

        if (!$hasWatermarkedImages) {
            return $payload;
        }

        $historyImages = $this->cleanOriginalImagesFromSourceWatermarkedMetafield($identity);
        if (count($historyImages) < count($images)) {
            throw new \RuntimeException('BEM backup create refused watermarked source images without clean history');
        }

        $title = $payload['title'] ?? null;
        $payload['images'] = array_map(static fn (array $image) => array_filter([
            'src' => $image['source_url'] ?? null,
            'alt' => $image['alt'] ?? $title,
            'position' => $image['position'] ?? null,
        ], static fn ($value) => $value !== null), array_slice($historyImages, 0, count($images)));

        Log::warning('BEM backup create replaced inherited watermarked payload images with clean originals', [
            'target_shop' => $target->domain,
            'source_product_id' => $this->sourceProductId,
            'images_count' => count($payload['images']),
        ]);

        return $payload;
    }

    private function cleanOriginalImagesFromSourceWatermarkedMetafield(BemImageIdentityService $identity): array
    {
        $source = Shop::find($this->sourceShopId);
        if (!$source) {
            return [];
        }

        $query = <<<'GQL'
        query SourceWatermarkedForBackupCreate($id: ID!) {
          product(id: $id) {
            metafield(namespace: "prod", key: "watermarked") {
              value
            }
          }
        }
        GQL;

        $response = $this->gql($source, $query, [
            'id' => "gid://shopify/Product/{$this->sourceProductId}",
        ]);
        $value = $response['data']['product']['metafield']['value'] ?? null;
        $payload = $value ? json_decode((string) $value, true) : null;
        if (!is_array($payload) || empty($payload['images']) || !is_array($payload['images'])) {
            return [];
        }

        $images = [];
        foreach ($payload['images'] as $index => $image) {
            $sourceUrl = $image['source_url'] ?? null;
            if (!$sourceUrl || $identity->isWatermarkedUrl($sourceUrl)) {
                continue;
            }

            $images[] = [
                'position' => (int) ($image['position'] ?? ($index + 1)),
                'source_url' => $sourceUrl,
                'alt' => $image['alt'] ?? ($this->payload['title'] ?? null),
            ];
        }

        usort($images, static fn ($a, $b) => ((int) $a['position']) <=> ((int) $b['position']));

        return $images;
    }

    private function payloadWithBemWatermarkedImages(array $payload, array $images): array
    {
        $title = $payload['title'] ?? null;

        $payload['images'] = array_map(static fn ($image) => array_filter([
            'src' => $image['watermarked_url'] ?? null,
            'alt' => $image['alt'] ?? $title,
            'position' => $image['position'] ?? null,
        ], static fn ($value) => $value !== null), $images);

        return $payload;
    }

    private function finalizeBemWatermarkedCreate(Shop $target, string $productGid, ?int $productLegacyId, array $bemCreate): void
    {
        try {
            $uploadService = app(BemShopifyStagedUploadService::class);
            $images = $this->ensureBemWatermarkedMediaAttached(
                target: $target,
                productGid: $productGid,
                images: $bemCreate['images'],
                uploadService: $uploadService
            );

            $images = $uploadService->applyFinalProductImageUrls(
                $target,
                $productGid,
                $images
            );

            app(BemProductWatermarkMetafieldService::class)->update($target, $productGid, [
                'source_shop' => $bemCreate['backup_shop']?->domain,
                'source_product_id' => $bemCreate['backup_source_product_id'],
                'source_product_gid' => $bemCreate['backup_source_product_gid'],
                'target_shop' => $target->domain,
                'target_product_id' => $productLegacyId,
                'target_product_gid' => $productGid,
                'updated_at' => now()->toIso8601String(),
                'dry_run' => false,
                'mode' => 'direct_create',
                'images' => $this->bemMetafieldImages($images),
            ]);

            $this->updateBemDirectCreateMirrorSnapshot($target, $images);

            Log::info('BEM direct create completed', [
                'target_shop' => $target->domain,
                'target_product_gid' => $productGid,
                'images_count' => count($images),
            ]);
        } catch (\Throwable $e) {
            Log::error('BEM direct create metafield/final URL update failed', [
                'target_shop' => $target->domain,
                'target_product_gid' => $productGid,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function ensureBemWatermarkedMediaAttached(
        Shop $target,
        string $productGid,
        array $images,
        BemShopifyStagedUploadService $uploadService
    ): array {
        $expected = count($images);
        if ($expected === 0) {
            return $images;
        }

        $actual = count($uploadService->fetchProductImages($target, $productGid));
        if ($actual >= $expected) {
            return $images;
        }

        sleep(5);
        $actual = count($uploadService->fetchProductImages($target, $productGid));
        if ($actual >= $expected) {
            return $images;
        }

        $missing = array_slice($images, $actual);
        if (empty($missing)) {
            return $images;
        }

        Log::warning('BEM direct create detected missing Shopify media; attaching missing images', [
            'target_shop' => $target->domain,
            'product_gid' => $productGid,
            'expected' => $expected,
            'actual' => $actual,
            'missing_count' => count($missing),
        ]);

        $this->attachImagesWithProductUpdate($target, $productGid, array_map(static fn ($image) => [
            'src' => $image['watermarked_url'] ?? null,
            'alt' => $image['alt'] ?? null,
            'position' => $image['position'] ?? null,
        ], $missing));

        sleep(5);
        $finalCount = count($uploadService->fetchProductImages($target, $productGid));
        if ($finalCount < $expected) {
            Log::warning('BEM direct create media count still below expected after retry attach', [
                'target_shop' => $target->domain,
                'product_gid' => $productGid,
                'expected' => $expected,
                'actual' => $finalCount,
            ]);
        }

        return $images;
    }

    private function bemMetafieldImages(array $images): array
    {
        return array_values(array_map(static fn ($image) => [
            'position' => $image['position'] ?? null,
            'source_url' => $image['source_url'] ?? null,
            'watermarked_url' => $image['watermarked_url'] ?? null,
            'filename' => $image['filename'] ?? null,
            'original_extension' => $image['original_extension'] ?? null,
            'status' => $image['status'] ?? null,
        ], $images));
    }

    private function updateBemDirectCreateMirrorSnapshot(Shop $target, array $images): void
    {
        $mirror = ProductMirror::where([
            'source_shop_id' => $this->sourceShopId,
            'source_product_id' => $this->sourceProductId,
            'target_shop_id' => $target->id,
        ])->first();

        if (!$mirror) {
            return;
        }

        $snapshot = is_array($mirror->last_snapshot ?? null)
            ? $mirror->last_snapshot
            : (is_string($mirror->last_snapshot) ? (json_decode($mirror->last_snapshot, true) ?: []) : []);

        $snapshotImages = array_values(array_map(function ($image) {
            $url = $image['watermarked_url'] ?? $image['uploaded_url'] ?? null;

            return [
                'src' => $url,
                'src_canon' => $this->canonUrl($url),
                'alt' => $image['alt'] ?? '',
                'position' => (int) ($image['position'] ?? 0),
            ];
        }, $images));

        $snapshot['images'] = $snapshotImages;
        $snapshot['images_fingerprint'] = $this->fingerprintImages($snapshotImages);
        $snapshot['bem_direct_create_synced_at'] = now()->toIso8601String();

        $mirror->last_snapshot = $snapshot;
        $mirror->save();
    }

    /**
     * Create product (2025-01), add media, fields, then variants + inventory.
     *
     * @return array [$productGid, $productLegacyId, $variantMap]
     */
    private function productCreate(Shop $shop, array $sourcePayload, ?string $metaDescription = null): array
    {
        $sourceVariantsById = $this->sourceVariantsById($sourcePayload);
        $title           = $sourcePayload['title'] ?? 'Untitled';
        $descriptionHtml = $sourcePayload['body_html'] ?? null;
        $vendor          = $sourcePayload['vendor'] ?? null;
        $productType     = $sourcePayload['product_type'] ?? null;

        $mutationCreate = <<<'GQL'
        mutation productCreate($product: ProductCreateInput!) {
          productCreate(product: $product) {
            product {
              id
              legacyResourceId
              title
              variants(first: 1) {
                nodes { id legacyResourceId inventoryItem { id } }
              }
            }
            userErrors { field message }
          }
        }
        GQL;

        $productInput = array_filter([
            'title'           => $title,
            'descriptionHtml' => $descriptionHtml,
            'vendor'          => $vendor,
            'productType'     => $productType,
            'status'          => $this->statusFromPayload($sourcePayload),
            'metafields'      => [[
                'namespace' => 'custom',
                'key' => 'parentproduct',
                'type' => 'number_integer',
                'value' => (string) $this->sourceProductId,
            ]],
            // create options on the product so variant optionValues are valid
            'productOptions'  => $this->buildProductOptionsFromPayload($sourcePayload),
        ], fn($x) => $x !== null && $x !== '');

        $res  = $this->gql($shop, $mutationCreate, ['product' => $productInput]);
        if (!empty($res['errors'])) {
            Log::error('productCreate GraphQL top-level errors', ['target' => $shop->domain, 'errors' => $res['errors']]);
            throw new \RuntimeException('GraphQL errors: '.json_encode($res['errors']));
        }
        $data = $res['data']['productCreate'] ?? null;
        if (!$data) throw new \RuntimeException('No data.productCreate in response: '.json_encode($res));
        if (!empty($data['userErrors'])) throw new \RuntimeException('productCreate userErrors: '.json_encode($data['userErrors']));

        $prod            = $data['product'] ?? null;
        $productGid      = $prod['id'] ?? null;
        $productLegacyId = isset($prod['legacyResourceId']) ? (int)$prod['legacyResourceId'] : null;
        if (!$productGid) throw new \RuntimeException('Missing product GID after create');

        // Persist the immutable target ID immediately. Any later failure can retry
        // against this product instead of creating a duplicate.
        $productMirror = $this->storeExistingProductMirror($shop, [
            'id' => $productLegacyId,
            'gid' => $productGid,
        ]);

        $hasOptions  = !empty($sourcePayload['options']);
        $hasManyVars = count($sourceVariantsById) > 1;

        if ($hasOptions || $hasManyVars) {
            $variantMap = $this->createAllOptionsAndVariants(
                shop: $shop,
                productGid: $productGid,
                src: $sourcePayload,
                locationLegacyId: $shop->location_legacy_id ?? null
            );
        } else {
            $sourceVariant = array_values($sourceVariantsById)[0];
            $defaultTargetVariant = $prod['variants']['nodes'][0] ?? [];
            $this->persistProvisionalSingleVariantMapping(
                $productMirror,
                $sourceVariant,
                $defaultTargetVariant
            );

            [$variantId, $inventoryItemId] = $this->updateDefaultVariant(
                shop: $shop,
                productGid: $productGid,
                src: $sourcePayload,
                locationLegacyId: $shop->location_legacy_id ?? null,
                sourceFlags: $this->readSourceVariantFlags(
                    sourceShop: Shop::find($this->sourceShopId),
                    sourceProductLegacyId: $this->sourceProductId
                )
            );

            $sv          = $sourceVariant;
            $svId        = (int) $sv['id'];
            $optionNames = array_map(fn($o) => (string)$o['name'], $sourcePayload['options'] ?? []);
            $optionsKey  = $this->buildOptionsKeyFromSource($sv, $optionNames);

            $variantMap = [[
                'source_variant_id'  => $svId,
                'source_options_key' => $optionsKey,
                'target_variant_gid' => $variantId,
                'target_variant_id'  => $this->legacyIdFromGid($variantId),
                'inventory_item_gid' => $inventoryItemId,
                'snapshot' => [
                    'price'   => $sv['price']   ?? null,
                    'sku'     => $sv['sku']     ?? null,
                    'barcode' => $sv['barcode'] ?? null,
                    'qty'     => $sv['inventory_quantity'] ?? null,
                ],
            ]];
        }

        $this->persistVariantMappings($shop, $productMirror, $variantMap);

        if (!empty($sourcePayload['images'])) {
            $this->attachImagesWithProductUpdate($shop, $productGid, $sourcePayload['images']);
        }

        $this->updateProductFields($shop, $productGid, $sourcePayload, $metaDescription);

        return [$productGid, $productLegacyId, $variantMap];
    }

    private function statusFromPayload(array $payload): string
    {
        return match (strtolower((string) ($payload['status'] ?? 'draft'))) {
            'active' => 'ACTIVE',
            'archived' => 'ARCHIVED',
            default => 'DRAFT',
        };
    }

    /**
     * Update the default variant (single-variant product) and set inventory.
     */
    private function updateDefaultVariant(
        Shop $shop,
        string $productGid,
        array $src,
        ?int $locationLegacyId = null,
        array $sourceFlags = []
    ): array {
        // Fetch default variant
        $q = <<<'GQL'
        query($id: ID!) {
          product(id: $id) {
            variants(first: 1) {
              nodes { id inventoryItem { id sku } }
            }
          }
        }
        GQL;
        $qr = $this->gql($shop, $q, ['id' => $productGid]);
        $node = $qr['data']['product']['variants']['nodes'][0] ?? null;
        if (!$node) throw new \RuntimeException("No default variant for $productGid");

        $variantId       = $node['id'];
        $inventoryItemId = $node['inventoryItem']['id'] ?? null;

        // Map from payload + fallbacks
        $v0        = $src['variants'][0] ?? [];
        $sourceVariantId = (int) ($v0['id'] ?? 0);
        if ($sourceVariantId <= 0) {
            throw new \RuntimeException('Cannot update default variant without source variant ID');
        }
        $price     = isset($v0['price']) ? (string)$v0['price'] : null;
        $compareAt = isset($v0['compare_at_price']) ? (string)$v0['compare_at_price'] : null;
        $barcode   = $v0['barcode'] ?? null;
        $sku       = $v0['sku'] ?? null;

        $policy = null;
        if (!empty($v0['inventory_policy'])) {
            $policy = (strtolower($v0['inventory_policy']) === 'continue') ? 'CONTINUE' : 'DENY';
        } elseif (!empty($sourceFlags['inventoryPolicy'])) {
            $policy = $sourceFlags['inventoryPolicy'];
        }

        $tracked = array_key_exists('inventory_management', $v0)
            ? (strtolower((string)$v0['inventory_management']) === 'shopify')
            : ($sourceFlags['tracked'] ?? null);

        $qty = isset($v0['inventory_quantity']) ? (int)$v0['inventory_quantity'] : null;
        if ($qty !== null && $tracked === null) {
            $tracked = true;
        }

        $requiresShipping = array_key_exists('requires_shipping', $v0)
            ? (bool)$v0['requires_shipping']
            : ($sourceFlags['requiresShipping'] ?? null);

        // Weight
        [$weightValue, $weightUnit] = (function() use ($v0) {
            if (isset($v0['weight'], $v0['weight_unit'])) {
                $map = ['kg'=>'KILOGRAMS','g'=>'GRAMS','lb'=>'POUNDS','oz'=>'OUNCES'];
                return [ (float)$v0['weight'], $map[strtolower((string)$v0['weight_unit'])] ?? null ];
            }
            if (isset($v0['grams'])) return [ (float)$v0['grams'], 'GRAMS' ];
            return [ null, null ];
        })();

        // Bulk update variant + inventoryItem
        $mutation = <<<'GQL'
        mutation($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
          productVariantsBulkUpdate(productId: $productId, variants: $variants) {
            productVariants {
              id
              price
              inventoryItem { id sku tracked requiresShipping measurement { weight { value unit } } }
            }
            userErrors { field message }
          }
        }
        GQL;

        $inventoryItemInput = array_filter([
            'sku'              => $sku,
            'tracked'          => $tracked,
            'requiresShipping' => $requiresShipping,
            'measurement'      => ($weightValue !== null && $weightUnit)
                ? ['weight' => ['value' => (float)$weightValue, 'unit' => $weightUnit]]
                : null,
        ], fn($v) => $v !== null);

        $variantInput = array_filter([
            'id'              => $variantId,
            'price'           => $price,
            'compareAtPrice'  => $compareAt,
            'barcode'         => $barcode,
            'inventoryPolicy' => $policy,
            'inventoryItem'   => $inventoryItemInput ?: null,
            'metafields'      => [[
                'namespace' => 'custom',
                'key' => 'parentvariant',
                'type' => 'number_integer',
                'value' => (string) $sourceVariantId,
            ]],
        ], fn($v) => $v !== null && $v !== []);

        if ($variantInput) {
            $mr = $this->gql($shop, $mutation, [
                'productId' => $productGid,
                'variants'  => [$variantInput],
            ]);
            if (!empty($mr['errors'])) {
                throw new \RuntimeException('GraphQL errors (variantsBulkUpdate): '.json_encode($mr['errors']));
            }
            $ue = $mr['data']['productVariantsBulkUpdate']['userErrors'] ?? [];
            if (!empty($ue)) {
                throw new \RuntimeException('productVariantsBulkUpdate userErrors: '.json_encode($ue));
            }
        }

        // Harden inventory item settings
        $this->inventoryItemUpdate(
            shop: $shop,
            inventoryItemId: $inventoryItemId,
            tracked: $tracked,
            requiresShipping: $requiresShipping,
            weightValue: $weightValue,
            weightUnit: $weightUnit
        );

        // Inventory
        Log::info('Inventory debug pre-apply', [
            'target_shop'         => $shop->domain,
            'productGid'          => $productGid,
            'variantId'           => $variantId ?? null,
            'inventoryItemId'     => $inventoryItemId ?? null,
            'locationLegacyId'    => $locationLegacyId ?? null,
            'qty_from_payload'    => $qty,
            'inventory_management'=> $v0['inventory_management'] ?? null,
            'inventory_policy'    => $v0['inventory_policy'] ?? null,
            'requires_shipping'   => $v0['requires_shipping'] ?? null,
        ]);

        if ($qty !== null && $inventoryItemId && $locationLegacyId) {
            $this->ensureInventoryAtLocation(
                $shop,
                $inventoryItemId,
                "gid://shopify/Location/{$locationLegacyId}",
                $qty
            );
        } else {
            Log::warning('Inventory ensure skipped (missing one of qty/inventoryItemId/locationLegacyId)', [
                'has_qty'           => $qty !== null,
                'has_inventoryItem' => (bool)$inventoryItemId,
                'has_location'      => (bool)$locationLegacyId,
            ]);
        }

        return [$variantId, $inventoryItemId];
    }

    /**
     * Set ABSOLUTE quantity for "available" using inventorySetQuantities (2025-01).
     */
    private function ensureInventoryAtLocation(Shop $shop, string $inventoryItemId, string $locationGid, int $desired): void
    {
        $mutation = <<<'GQL'
        mutation($input: InventorySetQuantitiesInput!) {
          inventorySetQuantities(input: $input) {
            userErrors { field message }
            inventoryAdjustmentGroup {
              reason
              changes { name delta }
            }
          }
        }
        GQL;

        $input = [
            'reason'                => 'correction',
            'name'                  => 'available',
            'ignoreCompareQuantity' => true, // we don't provide compareQuantity
            'quantities'            => [[
                'inventoryItemId' => $inventoryItemId,
                'locationId'      => $locationGid,
                'quantity'        => $desired,
            ]],
        ];

        // Log::info('Inventory setQuantities begin', [
        //     'target_shop'     => $shop->domain,
        //     'inventoryItemId' => $inventoryItemId,
        //     'locationGid'     => $locationGid,
        //     'quantity'        => $desired,
        // ]);

        $res = $this->gql($shop, $mutation, ['input' => $input]);

        if (!empty($res['errors'])) {
            Log::error('inventorySetQuantities top-level errors', ['target' => $shop->domain, 'errors' => $res['errors']]);
            throw new \RuntimeException('inventorySetQuantities errors: '.json_encode($res['errors']));
        }
        $ue = $res['data']['inventorySetQuantities']['userErrors'] ?? [];
        if (!empty($ue)) {
            Log::error('inventorySetQuantities userErrors', ['target' => $shop->domain, 'userErrors' => $ue]);
            throw new \RuntimeException('inventorySetQuantities userErrors: '.json_encode($ue));
        }

        // Log::info('inventorySetQuantities OK', [
        //     'target_shop' => $shop->domain,
        //     'changes'     => $res['data']['inventorySetQuantities']['inventoryAdjustmentGroup']['changes'] ?? null,
        //     'reason'      => $res['data']['inventorySetQuantities']['inventoryAdjustmentGroup']['reason'] ?? null,
        // ]);
    }

    private function fetchSourceMetaDescription(): ?string
    {
        try {
            $source = Shop::find($this->sourceShopId);
            if (!$source) {
                Log::warning('Meta description fetch skipped: missing source shop', [
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

            // Log::info('Source product meta description', [
            //     'source_shop_id'    => $this->sourceShopId,
            //     'source_product_id' => $this->sourceProductId,
            //     'meta_description'  => $description,
            // ]);

            return $description;
        } catch (\Throwable $e) {
            Log::warning('Meta description fetch failed', [
                'source_shop_id'    => $this->sourceShopId,
                'source_product_id' => $this->sourceProductId,
                'error'             => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function attachProductToManualCollection(Shop $shop, string $productGid): void
    {
        $collectionId = $this->manualCollectionIdForDomain($shop->domain ?? null);
        if (!$collectionId) {
            return;
        }

        $mutation = <<<'GQL'
        mutation AddProductToCollection($collectionId: ID!, $productIds: [ID!]!) {
          collectionAddProducts(id: $collectionId, productIds: $productIds) {
            userErrors { field message }
          }
        }
        GQL;

        try {
            $res = $this->gql($shop, $mutation, [
                'collectionId' => $collectionId,
                'productIds'   => [$productGid],
            ]);

            $top = $res['errors'] ?? [];
            $ue  = $res['data']['collectionAddProducts']['userErrors'] ?? [];
            if (!empty($top) || !empty($ue)) {
                Log::warning('collectionAddProducts issues', [
                    'target_shop'   => $shop->domain,
                    'collection_id' => $collectionId,
                    'product_gid'   => $productGid,
                    'errors'        => $top,
                    'user_errors'   => $ue,
                ]);
            } else {
                // Log::info('Product added to manual collection', [
                //     'target_shop'   => $shop->domain,
                //     'collection_id' => $collectionId,
                //     'product_gid'   => $productGid,
                // ]);
            }
        } catch (\Throwable $e) {
            Log::error('collectionAddProducts exception', [
                'target_shop'   => $shop->domain,
                'collection_id' => $collectionId,
                'product_gid'   => $productGid,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    private function manualCollectionIdForDomain(?string $domain): ?string
    {
        if (!$domain) {
            return null;
        }

        $key = strtolower($domain);
        return $this->manualCollectionMap[$key] ?? null;
    }

    private function setMetaDescriptionMetafield(Shop $shop, string $productGid, string $description): void
    {
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
                Log::warning('Meta description metafield userErrors', [
                    'target_shop' => $shop->domain,
                    'product_gid' => $productGid,
                    'userErrors'  => $ue,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Meta description metafield update failed', [
                'target_shop' => $shop->domain,
                'product_gid' => $productGid,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fetch all publication IDs (GIDs) for a shop via GraphQL.
     * Returns an array of strings like gid://shopify/Publication/xxx
     */
    private function fetchPublicationIds(Shop $shop): array
    {
        $q = <<<'GQL'
        query ListPublications {
          publications(first: 250) {
            nodes { id name }
          }
        }
        GQL;

        $res = $this->gql($shop, $q, []);
        if (!empty($res['errors'])) {
            Log::error('fetchPublicationIds top-level errors', ['target' => $shop->domain, 'errors' => $res['errors']]);
            throw new \RuntimeException('fetchPublicationIds errors: '.json_encode($res['errors']));
        }
        $nodes = $res['data']['publications']['nodes'] ?? [];
        $ids = [];
        foreach ($nodes as $n) {
            if (!empty($n['id'])) $ids[] = $n['id'];
        }
        return $ids;
    }

    /**
     * Publish a product to all publications of the target shop.
     * Uses DB credentials (Shop model) via gql().
     */
    private function publishProductToAllChannels(Shop $shop, string $productGid): void
    {
        $publications = $this->fetchPublicationIds($shop);
        if (empty($publications)) {
            Log::info('No publications found; skipping publish', ['target' => $shop->domain]);
            return;
        }

        $m = <<<'GQL'
        mutation PublishProduct($productId: ID!, $publicationId: ID!) {
          publishablePublish(id: $productId, input: { publicationId: $publicationId }) {
            publishable { ... on Product { id publicationCount } }
            userErrors { field message }
          }
        }
        GQL;

        foreach ($publications as $pubId) {
            try {
                $res = $this->gql($shop, $m, ['productId' => $productGid, 'publicationId' => $pubId]);
                $top = $res['errors'] ?? [];
                $ue  = $res['data']['publishablePublish']['userErrors'] ?? [];
                if (!empty($top) || !empty($ue)) {
                    Log::warning('publishablePublish issues', [
                        'target' => $shop->domain,
                        'product' => $productGid,
                        'publication' => $pubId,
                        'top' => $top,
                        'userErrors' => $ue,
                    ]);
                } else {
                    // Log::info('Published product to publication', [
                    //     'target' => $shop->domain,
                    //     'product' => $productGid,
                    //     'publication' => $pubId,
                    // ]);
                }
            } catch (\Throwable $e) {
                Log::error('publishablePublish exception', [
                    'target' => $shop->domain,
                    'product' => $productGid,
                    'publication' => $pubId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Attach images with productUpdate(media).
     */
    private function attachImagesWithProductUpdate(Shop $shop, string $productGid, array $images): void
    {
        $media = [];
        foreach ($images as $img) {
            $src = $img['src'] ?? null;
            if (!$src) continue;
            $media[] = array_filter([
                'mediaContentType' => 'IMAGE',
                'originalSource'   => $src,
                'alt'              => $img['alt'] ?? null,
            ], fn($v) => $v !== null);
        }
        if (!$media) return;

        $mutation = <<<'GQL'
        mutation UpdateProductWithNewMedia($product: ProductUpdateInput!, $media: [CreateMediaInput!]) {
          productUpdate(product: $product, media: $media) {
            product { id }
            userErrors { field message }
          }
        }
        GQL;

        $res = $this->gql($shop, $mutation, [
            'product' => ['id' => $productGid],
            'media'   => $media,
        ]);

        if (!empty($res['errors'])) {
            Log::error('productUpdate(media) top-level errors', ['target' => $shop->domain, 'errors' => $res['errors']]);
            throw new \RuntimeException('GraphQL errors (productUpdate media): ' . json_encode($res['errors']));
        }
        $ue = $res['data']['productUpdate']['userErrors'] ?? [];
        if (!empty($ue)) {
            Log::error('productUpdate(media) userErrors', ['target' => $shop->domain, 'userErrors' => $ue]);
            throw new \RuntimeException('productUpdate(media) userErrors: ' . json_encode($ue));
        }
    }

    private function updateProductFields(Shop $shop, string $productGid, array $src, ?string $metaDescription = null): void
    {
        $tags = array_values(array_filter(array_map('trim', explode(',', (string)($src['tags'] ?? '')))));
        if (!in_array('noutati', $tags, true)) {
            $tags[] = 'noutati';
        }
        if (!in_array('de_tradus', $tags, true)) {
            $tags[] = 'de_tradus';
        }
        $statusMap = ['active'=>'ACTIVE','draft'=>'DRAFT','archived'=>'ARCHIVED'];
        if ((int)$shop->id === 8 || $shop->domain === 'eiluminat-bg.myshopify.com') {
            $status = 'DRAFT';
        } else {
            $status = null;
            if (!empty($src['status']) && isset($statusMap[strtolower($src['status'])])) {
                $status = $statusMap[strtolower($src['status'])];
            }
        }

        $mutation = <<<'GQL'
        mutation productUpdate($product: ProductUpdateInput!) {
          productUpdate(product: $product) {
            product { id status productType tags }
            userErrors { field message }
          }
        }
        GQL;

        $seoInput = null;
        if ($metaDescription !== null && $metaDescription !== '') {
            $seoInput = ['description' => $metaDescription];
        }

        $input = array_filter([
            'id'          => $productGid,
            'handle'      => isset($src['handle']) && trim((string) $src['handle']) !== '' ? (string) $src['handle'] : null,
            'tags'        => $tags ?: null,
            'productType' => $src['product_type'] ?? null,
            'vendor'      => $src['vendor'] ?? null,
            'status'      => $status,
            'seo'         => $seoInput,
        ], fn($v) => $v !== null && $v !== []);

        if (!$input) return;

        $res = $this->gql($shop, $mutation, ['product' => $input]);
        if (!empty($res['errors'])) {
            Log::error('productUpdate errors', ['target' => $shop->domain, 'res' => $res]);
            throw new \RuntimeException('productUpdate errors: '.json_encode($res['errors']));
        }
        $userErrors = $res['data']['productUpdate']['userErrors'] ?? [];
        if (!empty($userErrors)) {
            Log::error('productUpdate userErrors', ['target' => $shop->domain, 'userErrors' => $userErrors]);
            throw new \RuntimeException('productUpdate userErrors: '.json_encode($userErrors));
        }

        if ($seoInput) {
            $this->setMetaDescriptionMetafield($shop, $productGid, $seoInput['description']);
        }
    }

    private function readSourceVariantFlags(?Shop $sourceShop, int $sourceProductLegacyId): array
    {
        $defaults = [
            'inventoryPolicy'  => null,
            'tracked'          => null,
            'requiresShipping' => null,
        ];
        if (!$sourceShop) return $defaults;

        $gid = "gid://shopify/Product/{$sourceProductLegacyId}";
        $q = <<<'GQL'
        query($id: ID!) {
          product(id: $id) {
            variants(first: 1) {
              nodes {
                inventoryPolicy
                inventoryItem { tracked requiresShipping }
              }
            }
          }
        }
        GQL;

        try {
            $r = $this->gql($sourceShop, $q, ['id' => $gid]);
            $node = $r['data']['product']['variants']['nodes'][0] ?? null;
            if (!$node) return $defaults;

            return [
                'inventoryPolicy'  => $node['inventoryPolicy'] ?? null,
                'tracked'          => $node['inventoryItem']['tracked'] ?? null,
                'requiresShipping' => $node['inventoryItem']['requiresShipping'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning('readSourceVariantFlags failed', ['err' => $e->getMessage()]);
            return $defaults;
        }
    }

    private function legacyIdFromGid(?string $gid): ?int
    {
        if (!$gid) return null;
        $pos = strrpos($gid, '/');
        if ($pos === false) return null;
        return (int) substr($gid, $pos + 1);
    }

    private function inventoryItemUpdate(
        Shop $shop,
        string $inventoryItemId,
        ?bool $tracked,
        ?bool $requiresShipping,
        ?float $weightValue,
        ?string $weightUnit
    ): void {
        $input = array_filter([
            'tracked'          => $tracked,
            'requiresShipping' => $requiresShipping,
            'measurement'      => ($weightValue !== null && $weightUnit)
                ? ['weight' => ['value' => $weightValue, 'unit' => $weightUnit]]
                : null,
        ], fn ($v) => $v !== null);

        if (!$input) return;

        $mutation = <<<'GQL'
        mutation($id: ID!, $input: InventoryItemInput!) {
          inventoryItemUpdate(id: $id, input: $input) {
            inventoryItem {
              id
              tracked
              requiresShipping
              measurement { weight { value unit } }
            }
            userErrors { field message }
          }
        }
        GQL;

        $res = $this->gql($shop, $mutation, [
            'id'    => $inventoryItemId,
            'input' => $input,
        ]);

        if (!empty($res['errors'])) {
            throw new \RuntimeException('GraphQL errors (inventoryItemUpdate): '.json_encode($res['errors']));
        }
        $ue = $res['data']['inventoryItemUpdate']['userErrors'] ?? [];
        if (!empty($ue)) {
            throw new \RuntimeException('inventoryItemUpdate userErrors: '.json_encode($ue));
        }
    }

    /**
     * Create all options + variants, set stock for each, and return a mapping array
     * for VariantMirror upserts.
     */
    private function createAllOptionsAndVariants(
        Shop $shop,
        string $productGid,
        array $src,
        ?int $locationLegacyId = null
    ): array {
        // Option names (max 3)
        $optionNames = [];
        foreach (array_slice(($src['options'] ?? []), 0, 3) as $o) {
            $n = trim((string)($o['name'] ?? ''));
            if ($n !== '') $optionNames[] = $n;
        }

        // Build variants input
        $mapUnit = fn($u) => ['kg'=>'KILOGRAMS','g'=>'GRAMS','lb'=>'POUNDS','oz'=>'OUNCES'][strtolower((string)$u)] ?? null;

        $sourceVariants = array_values($src['variants'] ?? []);
        $sourceVariantsById = [];
        $variantsInput = [];
        foreach ($sourceVariants as $v) {
            $sourceVariantId = isset($v['id']) ? (int) $v['id'] : 0;
            if ($sourceVariantId <= 0) {
                throw new \RuntimeException('Cannot create target variant without source variant ID');
            }
            if (isset($sourceVariantsById[(string) $sourceVariantId])) {
                throw new \RuntimeException('Duplicate source variant ID in create payload: '.$sourceVariantId);
            }
            $sourceVariantsById[(string) $sourceVariantId] = $v;

            // option1/2/3 → optionValues with correct optionName
            $ov = [];
            $vals = [ $v['option1'] ?? null, $v['option2'] ?? null, $v['option3'] ?? null ];
            foreach ($vals as $i => $val) {
                $val = trim((string)$val);
                $optName = $optionNames[$i] ?? null;
                if ($optName && $val !== '') {
                    $ov[] = ['name' => $val, 'optionName' => $optName];
                }
            }

            $tracked = isset($v['inventory_management']) ? (strtolower((string)$v['inventory_management']) === 'shopify') : null;
            if (isset($v['inventory_quantity']) && $tracked === null) $tracked = true;

            $measurement = null;
            if (isset($v['weight'], $v['weight_unit']) && ($u = $mapUnit($v['weight_unit']))) {
                $measurement = ['weight' => ['value' => (float)$v['weight'], 'unit' => $u]];
            } elseif (isset($v['grams'])) {
                $measurement = ['weight' => ['value' => (float)$v['grams'], 'unit' => 'GRAMS']];
            }

            $inventoryItem = array_filter([
                'sku'              => $v['sku'] ?? null,
                'tracked'          => $tracked,
                'requiresShipping' => array_key_exists('requires_shipping', $v) ? (bool)$v['requires_shipping'] : null,
                'measurement'      => $measurement,
            ], fn($x) => $x !== null);

            $variantsInput[] = array_filter([
                'barcode'         => $v['barcode'] ?? null,
                'price'           => isset($v['price']) ? (float)$v['price'] : null,
                'compareAtPrice'  => isset($v['compare_at_price']) ? (float)$v['compare_at_price'] : null,
                'inventoryPolicy' => isset($v['inventory_policy']) && strtolower($v['inventory_policy']) === 'continue' ? 'CONTINUE' : 'DENY',
                'optionValues'    => $ov,
                'inventoryItem'   => $inventoryItem ?: null,
                'metafields'      => [[
                    'namespace' => 'custom',
                    'key' => 'parentvariant',
                    'type' => 'number_integer',
                    'value' => (string) $sourceVariantId,
                ]],
            ], fn($x) => $x !== null && $x !== []);
        }

        if (!$variantsInput) {
            Log::warning('No variantsInput to create', ['product' => $productGid]);
            return [];
        }

        // Bulk create with 2025-01 strategy enum
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

        $resp = $this->gql($shop, $mutation, [
            'productId' => $productGid,
            'variants'  => $variantsInput,
            'strategy'  => 'REMOVE_STANDALONE_VARIANT',
        ]);

        if (!empty($resp['errors'])) {
            throw new \RuntimeException('GraphQL errors (variantsBulkCreate): '.json_encode($resp['errors']));
        }
        $ue = $resp['data']['productVariantsBulkCreate']['userErrors'] ?? [];
        if (!empty($ue)) {
            throw new \RuntimeException('productVariantsBulkCreate userErrors: '.json_encode($ue));
        }

        $created = $resp['data']['productVariantsBulkCreate']['productVariants'] ?? [];
        if (count($created) !== count($sourceVariants)) {
            throw new \RuntimeException(sprintf(
                'Variant create response count mismatch: expected %d, received %d',
                count($sourceVariants),
                count($created)
            ));
        }

        // Identity is returned from the atomically-created parentvariant metafield.
        // Response order and mutable option values are never used for correlation.
        $optionNames = array_map(fn($o) => (string)$o['name'], $src['options'] ?? []);
        $variantMap = [];
        foreach ($created as $node) {
            $svId       = (int) ($node['metafield']['value'] ?? 0);
            $sv         = $sourceVariantsById[(string) $svId] ?? null;
            if (!$sv || isset($variantMap[(string) $svId])) {
                throw new \RuntimeException('Invalid or duplicate parentvariant returned after variant create');
            }
            $key        = $this->buildOptionsKeyFromSource($sv, $optionNames);
            $tvGid      = $node['id'] ?? null;
            if (!$tvGid) {
                throw new \RuntimeException('Missing target variant GID in bulk create response');
            }
            $tvId       = $this->legacyIdFromGid($tvGid);
            $invItemGid = $node['inventoryItem']['id'] ?? null;

            $variantMap[(string) $svId] = [
                'source_variant_id'  => $svId,
                'source_options_key' => $key,
                'target_variant_gid' => $tvGid,
                'target_variant_id'  => $tvId,
                'inventory_item_gid' => $invItemGid,
                'snapshot' => [
                    'price'   => $sv['price']   ?? null,
                    'sku'     => $sv['sku']     ?? null,
                    'barcode' => $sv['barcode'] ?? null,
                    'qty'     => $sv['inventory_quantity'] ?? null,
                ],
            ];
        }

        $variantMap = array_values($variantMap);

        // Set stock for each created variant (if we have a location)
        if ($locationLegacyId) {
            $locationGid = "gid://shopify/Location/{$locationLegacyId}";
            foreach ($variantMap as $vm) {
                $qty = $vm['snapshot']['qty'] ?? null;
                if ($qty !== null && $vm['inventory_item_gid']) {
                    $this->ensureInventoryAtLocation($shop, $vm['inventory_item_gid'], $locationGid, (int)$qty);
                }
            }
        }

        return $variantMap;
    }

    /**
     * Build productOptions input from source payload.
     */
    private function buildProductOptionsFromPayload(array $src): array
    {
        // Option names (max 3)
        $names = [];
        foreach (array_slice(($src['options'] ?? []), 0, 3) as $o) {
            $name = trim((string)($o['name'] ?? ''));
            if ($name !== '') $names[] = $name;
        }

        // Aggregate distinct values per option index from variants
        $valuesByIndex = [[], [], []];
        foreach (($src['variants'] ?? []) as $v) {
            $o1 = isset($v['option1']) ? trim((string)$v['option1']) : null;
            $o2 = isset($v['option2']) ? trim((string)$v['option2']) : null;
            $o3 = isset($v['option3']) ? trim((string)$v['option3']) : null;
            if ($o1 !== null && $o1 !== '') $valuesByIndex[0][$o1] = true;
            if ($o2 !== null && $o2 !== '') $valuesByIndex[1][$o2] = true;
            if ($o3 !== null && $o3 !== '') $valuesByIndex[2][$o3] = true;
        }

        $out = [];
        foreach ($names as $i => $name) {
            // Ensure values are strings; PHP may cast numeric-string keys to ints.
            $vals = array_map('strval', array_keys($valuesByIndex[$i] ?? []));
            $out[] = array_filter([
                'name'   => $name,
                // 2025-01: values are array of OptionValueInput { name: String! }
                'values' => $vals ? array_map(fn($x) => ['name' => (string)$x], $vals) : null,
            ], fn($v) => $v !== null);
        }
        return $out;
    }

    /**
     * Deterministic key for a source variant (REST) given option names order.
     * Example: Color=Red|Size=M
     */
    private function buildOptionsKeyFromSource(array $variant, array $optionNames): string
    {
        $parts = [];
        foreach ($optionNames as $i => $name) {
            $val = trim((string)($variant['option'.($i+1)] ?? ''));
            $parts[] = $name.'='.$val;
        }
        return implode('|', $parts);
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
}
