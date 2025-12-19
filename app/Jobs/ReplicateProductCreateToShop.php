<?php

namespace App\Jobs;

use App\Models\ProductMirror;
use App\Models\VariantMirror;
use App\Models\Shop;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Services\Shopify\ProductImagesBackupService;

class ReplicateProductCreateToShop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = [10, 30, 60, 120];

    /**
     * Manual collection IDs per target shop domain.
     * TODO: move to config if the list grows/changing frequently.
     */
    private array $manualCollectionMap = [
        'lustreled.myshopify.com'      => 'gid://shopify/Collection/622468399449',
        'powerleds-ro.myshopify.com'   => 'gid://shopify/Collection/624000663891',
    ];

    public function __construct(
        public int $targetShopId,
        public int $sourceShopId,
        public int $sourceProductId,
        public array $payload
    ) {}

    public function handle(): void
    {
        try {
            $target = Shop::findOrFail($this->targetShopId);

            Log::info('ReplicateProductCreate target shop debug', [
                'target_shop_id'   => $this->targetShopId,
                'target_shop_name' => $target->name ?? null,
                'target_shop_domain' => $target->domain ?? null,
            ]);

            $metaDescription = $this->fetchSourceMetaDescription();

            [$productGid, $productLegacyId, $variantMap] = $this->productCreate($target, $this->payload, $metaDescription);

            $this->attachProductToManualCollection($target, $productGid);

            // Save product mapping (mirror)
            $pm = ProductMirror::updateOrCreate(
                [
                    'source_shop_id'    => $this->sourceShopId,
                    'source_product_id' => $this->sourceProductId,
                    'target_shop_id'    => $target->id,
                ],
                [
                    'source_product_gid' => "gid://shopify/Product/{$this->sourceProductId}",
                    'target_product_gid' => $productGid,
                    'target_product_id'  => $productLegacyId,
                ]
            );

            $mirror = ProductMirror::where([
                'source_shop_id'    => $this->sourceShopId,
                'source_product_id' => $this->sourceProductId,
                'target_shop_id'    => $target->id,
            ])->first();

            $imgs = $this->extractSourceImages($this->payload);

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

            // Save variant mappings (mirrors)
            if (!empty($variantMap)) {
                foreach ($variantMap as $vm) {
                    VariantMirror::updateOrCreate(
                        [
                            'product_mirror_id' => $pm->id,
                            'source_variant_id' => $vm['source_variant_id'], // may be null in some edge payloads
                        ],
                        [
                            'source_options_key'    => $vm['source_options_key'],
                            'target_variant_gid'    => $vm['target_variant_gid'],
                            'target_variant_id'     => $vm['target_variant_id'],
                            'inventory_item_gid'    => $vm['inventory_item_gid'] ?? null,
                            'last_snapshot'         => $vm['snapshot'],
                            'variant_fingerprint'   => hash('sha256', json_encode([
                                'price'   => $vm['snapshot']['price']   ?? null,
                                'sku'     => $vm['snapshot']['sku']     ?? null,
                                'barcode' => $vm['snapshot']['barcode'] ?? null,
                            ])),
                            'inventory_fingerprint' => hash('sha256', json_encode([
                                'qty' => $vm['snapshot']['qty'] ?? null,
                            ])),
                        ]
                    );
                }
            }

            // Log::info('Replicated product to target shop', [
            //     'target'            => $target->domain,
            //     'target_gid'        => $productGid,
            //     'target_id'         => $productLegacyId,
            //     'source_product_id' => $this->sourceProductId,
            //     'variants_mapped'   => count($variantMap ?? []),
            // ]);

            // Optional: publish the product to all sales channels configured on the target shop
            try {
                $this->publishProductToAllChannels($target, $productGid);
            } catch (\Throwable $e) {
                Log::warning('Publish to channels failed (non-fatal)', [
                    'target' => $target->domain,
                    'productGid' => $productGid,
                    'error' => $e->getMessage(),
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

    /**
     * Create product (2025-01), add media, fields, then variants + inventory.
     *
     * @return array [$productGid, $productLegacyId, $variantMap]
     */
    private function productCreate(Shop $shop, array $sourcePayload, ?string $metaDescription = null): array
    {
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

        // Attach images if any
        if (!empty($sourcePayload['images'])) {
            try { $this->attachImagesWithProductUpdate($shop, $productGid, $sourcePayload['images']); }
            catch (\Throwable $e) { Log::error('attachImages failed', ['target'=>$shop->domain,'product'=>$productGid,'msg'=>$e->getMessage()]); }
        }

        // Update simple product fields
        $this->updateProductFields($shop, $productGid, $sourcePayload, $metaDescription);

        // Multi-variant or single?
        $hasOptions  = !empty($sourcePayload['options']);
        $hasManyVars = count($sourcePayload['variants'] ?? []) > 1;

        if ($hasOptions || $hasManyVars) {
            $variantMap = $this->createAllOptionsAndVariants(
                shop: $shop,
                productGid: $productGid,
                src: $sourcePayload,
                locationLegacyId: $shop->location_legacy_id ?? null
            );
            return [$productGid, $productLegacyId, $variantMap];
        }

        // Single-variant flow
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

        $sv          = $sourcePayload['variants'][0] ?? [];
        $svId        = $sv['id'] ?? null; // legacy id from webhook
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

        return [$productGid, $productLegacyId, $variantMap];
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
        $statusMap = ['active'=>'ACTIVE','draft'=>'DRAFT','archived'=>'ARCHIVED'];
        $status = null;
        if (!empty($src['status']) && isset($statusMap[strtolower($src['status'])])) {
            $status = $statusMap[strtolower($src['status'])];
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
            'tags'        => $tags ?: null,
            'productType' => $src['product_type'] ?? null,
            'vendor'      => $src['vendor'] ?? null,
            'status'      => $status,
            'seo'         => $seoInput,
        ], fn($v) => $v !== null && $v !== []);

        if (!$input) return;

        $res = $this->gql($shop, $mutation, ['product' => $input]);
        if (!empty($res['errors']) || !empty(($res['data']['productUpdate']['userErrors'] ?? []))) {
            Log::error('productUpdate errors', ['target' => $shop->domain, 'res' => $res]);
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

        $variantsInput = [];
        foreach (($src['variants'] ?? []) as $v) {
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
              selectedOptions { name value }
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

        // Build a mapping between source variants and created target variants via options key
        $optionNames = array_map(fn($o) => (string)$o['name'], $src['options'] ?? []);

        $byKeySource = [];
        foreach (($src['variants'] ?? []) as $sv) {
            $key = $this->buildOptionsKeyFromSource($sv, $optionNames);
            $byKeySource[$key] = $sv;
        }

        $variantMap = [];
        foreach ($created as $node) {
            $key        = $this->buildOptionsKeyFromSelectedOptions($node['selectedOptions'] ?? []);
            $sv         = $byKeySource[$key] ?? [];
            $svId       = $sv['id'] ?? null; // legacy id from source webhook
            $tvGid      = $node['id'] ?? null;
            $tvId       = $this->legacyIdFromGid($tvGid);
            $invItemGid = $node['inventoryItem']['id'] ?? null;

            $variantMap[] = [
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

    /**
     * Deterministic key for a GraphQL selectedOptions node array.
     */
    private function buildOptionsKeyFromSelectedOptions(array $selectedOptions): string
    {
        $parts = [];
        foreach ($selectedOptions as $so) {
            $parts[] = ($so['name'] ?? '').'='.trim((string)($so['value'] ?? ''));
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
