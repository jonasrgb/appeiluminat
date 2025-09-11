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

class ReplicateProductUpdateToShop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = [10, 30, 60, 120];

    public function __construct(
        public int $targetShopId,
        public int $sourceShopId,
        public int $sourceProductId,
        public array $payload
    ) {}

public function handle(): void
{
    $target = Shop::findOrFail($this->targetShopId);

    $mirror = ProductMirror::where([
        'source_shop_id'    => $this->sourceShopId,
        'target_shop_id'    => $this->targetShopId,
        'source_product_id' => $this->sourceProductId,
    ])->first();

    if (!$mirror || !$mirror->target_product_gid) {
        Log::warning('Update skipped: no product mirror mapping', [
            'target_shop' => $target->domain,
            'source_shop' => $this->sourceShopId,
            'source_pid'  => $this->sourceProductId,
        ]);
        return;
    }

    Log::info('Replicate update start', [
        'target_shop' => $target->domain,
        'target_gid'  => $mirror->target_product_gid,
        'source_pid'  => $this->sourceProductId,
    ]);

    // === 1) Core product diff & patch ===
    $lastSnap = $mirror->last_snapshot;
    if (is_string($lastSnap)) {
        $lastSnap = json_decode($lastSnap, true) ?: [];
    } elseif (!is_array($lastSnap)) {
        $lastSnap = [];
    }

    $productDiff = $this->computeProductDiff($this->payload, $lastSnap);
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
        Log::info('Options changed → syncing', [
            'target_shop' => $target->domain,
            'names'       => array_map(fn($o) => $o['name'], $srcOptions),
        ]);
        // lăsăm doar raport aici; crearea efectivă doar dacă target are "Default Title"
    } else {
        Log::info('Options unchanged, skipping', ['target_shop' => $target->domain]);
    }

    // === 1.2) Variant diff (raport) ===
    $variantDiff = $this->computeVariantDiff($this->payload, $mirror, $srcOptions);
    Log::info('Variant diff report', [
        'toCreate' => array_keys($variantDiff['toCreate']),
        'toUpdate' => array_keys($variantDiff['toUpdate']),
        'toDelete' => array_keys($variantDiff['toDelete']),
    ]);

    // === 2) Images diff (fingerprint) & sync ===
    $srcImages = $this->extractSourceImages($this->payload);
    $currFp    = $this->fingerprintImages($srcImages);
    $prevFp    = $lastSnap['images_fingerprint'] ?? null;

    if ($currFp !== $prevFp) {
        Log::info('Images changed → syncing', [
            'target_shop' => $target->domain,
            'changed'     => true,
        ]);
        $this->syncImagesReplaceAll($target, $mirror->target_product_gid, $srcImages);
        Log::info('Images synced', [
            'target_shop' => $target->domain,
            'count'       => count($srcImages),
        ]);
    } else {
        Log::info('Images unchanged, skipping sync', ['target_shop' => $target->domain]);
    }

    // === 2.1) Dacă target are "Default Title", creăm schema de opțiuni (fără variante) ===
    $desiredOptions = $this->buildOptionCreateInputsFromPayload($this->payload);
    if (!empty($desiredOptions)) {
        $targetOptions = $this->fetchTargetOptions($target, $mirror->target_product_gid);

        $hasOnlyDefaultTitle = false;
        if (count($targetOptions) === 1) {
            $only = $targetOptions[0];
            $name = strtolower($only['name'] ?? '');
            $vals = array_map('strval', $only['values'] ?? []);
            $hasOnlyDefaultTitle = ($name === 'title') || ($vals === ['Default Title']);
        }

        if ($hasOnlyDefaultTitle) {
            Log::info('Options changed → creating on target', [
                'target_shop' => $target->domain,
                'names' => array_map(fn($o) => $o['name'] ?? null, $desiredOptions),
            ]);
            $this->productOptionsCreate(
                shop: $target,
                productGid: $mirror->target_product_gid,
                options: $desiredOptions,
                variantStrategy: 'LEAVE_AS_IS'
            );
        } else {
            Log::info('Options exist on target → applying set (rename/reorder/add/remove)', [
                'target_shop' => $target->domain,
                'names' => array_map(fn($o) => $o['name'] ?? null, $desiredOptions),
            ]);
            // Încercăm schema nouă productOptionsSet; dacă nu există în versiunea de API, logăm și continuăm fără a arunca.
            try {
                $this->productOptionsSet(
                    shop: $target,
                    productGid: $mirror->target_product_gid,
                    options: $desiredOptions,
                    variantStrategy: 'LEAVE_AS_IS'
                );
            } catch (\Throwable $e) {
                Log::warning('productOptionsSet unavailable or failed; options not updated via Set', [
                    'target_shop' => $target->domain,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // === 3) Variants: map + bulk economic + SKU/barcode (productSet) + inventory ===
    $srcOptions   = $this->normalizeOptionsFromPayload($this->payload);
    $srcVariants  = $this->normalizeSourceVariants($this->payload, $srcOptions);
    $isDefaultProduct = $this->isDefaultProduct($srcOptions);

    // Încearcă să recuperezi starea reală "tracked" din shop-ul sursă (pentru a replica Track quantity)
    $source = Shop::find($this->sourceShopId);
    $sourceTrackedByKey = [];
    if ($source) {
        try {
            $sourceProductGid = 'gid://shopify/Product/' . $this->sourceProductId;
            $sourceTrackedByKey = $this->fetchTrackedMapByKey(
                $source,
                $sourceProductGid,
                array_map(fn($o) => $o['name'], $srcOptions)
            );
        } catch (\Throwable $e) {
            Log::warning('Source tracked fetch failed; using payload fallback', [
                'source_shop' => $source?->domain,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // 3.0) Asigură maparea VariantMirror (prima rulare)
    $this->ensureVariantMirrors($mirror, $target, $srcOptions, $srcVariants);

    // 3.0.1) Șterge variantele care nu mai există în sursă (toDelete)
    if (!empty($variantDiff['toDelete'])) {
        foreach ($variantDiff['toDelete'] as $rawKey => $vmDel) {
            try {
                $gid = $vmDel->target_variant_gid ?? null;
                if (!$gid) continue;
                $this->productVariantDelete($target, $gid);
                // Curăță mirror-ul
                $vmDel->delete();
                Log::info('Variant deleted on target', [
                    'target_shop' => $target->domain,
                    'key'         => $this->canonKey($rawKey),
                    'variant_gid' => $gid,
                ]);
            } catch (\Throwable $e) {
                Log::error('Variant delete failed', [
                    'target_shop' => $target->domain,
                    'key'         => $rawKey,
                    'error'       => $e->getMessage(),
                ]);
            }
        }
    }

    // 3.0.2) Creează variante lipsă în target (toCreate) – folosind productVariantsBulkCreate (compatibil cu 2025-01)
    if (!empty($variantDiff['toCreate'])) {
        if ($isDefaultProduct) {
            // Guard pentru produse cu varianta implicită "Default Title":
            // nu încercăm create (Shopify nu permite a doua variantă fără opțiuni),
            // ci facem bootstrap mapping către varianta existentă și forțăm update-urile ulterior.
            try {
                $optionNames = array_map(fn($o) => $o['name'], $srcOptions);
                $targetMap = $this->fetchTargetVariantsMap($target, $mirror->target_product_gid, $optionNames);

                foreach ($variantDiff['toCreate'] as $rawKey => $sv) {
                    $ckey = $this->canonKey($rawKey);
                    $existingGid = $targetMap[$ckey] ?? null;
                    if (!$existingGid) {
                        Log::warning('Default-variant guard: no target variant gid found for key; skipping create', [
                            'target_shop' => $target->domain,
                            'key'         => $ckey,
                        ]);
                        continue;
                    }

                    // Bootstrap/actualizează mirror-ul, dar lasă fingerprint-urile NULL pentru a declanșa update-urile mai jos
                    VariantMirror::updateOrCreate(
                        [
                            'product_mirror_id' => $mirror->id,
                            'source_options_key'=> $ckey,
                            'source_variant_id' => $sv['source_variant_id'] ?? null,
                        ],
                        [
                            'target_variant_gid'    => $existingGid,
                            'variant_fingerprint'   => null,
                            'inventory_fingerprint' => null,
                        ]
                    );

                    Log::info('Default-variant guard: bootstrapped mirror mapping', [
                        'target_shop' => $target->domain,
                        'key'         => $ckey,
                        'variant_gid' => $existingGid,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Default-variant guard failed during bootstrap', [
                    'target_shop' => $target->domain,
                    'error'       => $e->getMessage(),
                ]);
            }
            // Sărim explicit create-ul pentru default variant
        } else {
            try {
                $createdMap = $this->productVariantsBulkCreateForUpdate($target, $mirror->target_product_gid, $variantDiff['toCreate'], $srcOptions);
                foreach ($variantDiff['toCreate'] as $rawKey => $sv) {
                    $ckey = $this->canonKey($rawKey);
                    $newGid = $createdMap[$ckey]['variant_gid'] ?? null;
                    if (!$newGid) {
                        Log::warning('Variant bulk create did not return gid for key', ['target_shop'=>$target->domain,'key'=>$ckey]);
                        continue;
                    }
                    VariantMirror::updateOrCreate(
                        [
                            'product_mirror_id' => $mirror->id,
                            'source_options_key'=> $ckey,
                            'source_variant_id' => $sv['source_variant_id'] ?? null,
                        ],
                        [
                            'target_variant_gid'    => $newGid,
                            'variant_fingerprint'   => $sv['variant_fingerprint'] ?? null,
                            'inventory_fingerprint' => $sv['inventory_fingerprint'] ?? null,
                        ]
                    );
                    Log::info('Variant created on target', [
                        'target_shop' => $target->domain,
                        'key'         => $ckey,
                        'variant_gid' => $newGid,
                    ]);

                    // set stock if provided
                    if (isset($sv['inventory_quantity']) && $sv['inventory_quantity'] !== null) {
                        [$itemId, $locIds] = $this->fetchInventoryItemAndLocations($target, $newGid);
                        if ($itemId) {
                            $this->inventorySetQuantities($target, $itemId, $locIds, (int)$sv['inventory_quantity']);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Variants bulk create failed', ['target_shop'=>$target->domain, 'error'=>$e->getMessage()]);
            }
        }
    }

    // 3.1) Reîncarcă mapul după eventualele inserări
    $mirrorByKey = $this->loadVariantMirrorMap($mirror);

    // 3.2) BULK economic (price/compareAt/taxable/inventoryPolicy [+ greutate dacă decizi în builder])
    $bulkUpdates = [];
    foreach ($srcVariants as $key => $sv) {
        $ckey = $this->canonKey($key);
        $vm   = $mirrorByKey[$ckey] ?? null;
        if (!$vm || empty($vm->target_variant_gid)) continue;

        if ((string)$vm->variant_fingerprint !== (string)$sv['variant_fingerprint']) {
            $inp = $this->buildVariantUpdateInput($vm->target_variant_gid, $sv);
            if (!empty($inp)) $bulkUpdates[] = $inp;
        }
    }

    if (!empty($bulkUpdates)) {
        Log::info('Variant updates payload (bulk economic fields)', array_map(fn($u) => [
            'id'             => $u['id'] ?? null,
            'price'          => $u['price'] ?? null,
            'compareAtPrice' => $u['compareAtPrice'] ?? null,
            'taxable'        => $u['taxable'] ?? null,
            'inventoryPolicy'=> $u['inventoryPolicy'] ?? null,
        ], $bulkUpdates));
        $this->productVariantsBulkUpdate($target, $mirror->target_product_gid, $bulkUpdates);
    } else {
        Log::info('Variant bulk no-op (no economic changes)');
    }

    // 3.3) SKU / Barcode – cu productSet (necesită optionValues pentru fiecare variantă)
    // Construim input-ul pe baza CHEILOR din sursă, ca să fie aliniat cu productOptions dorite.
    $sourceOptions = $this->normalizeOptionsFromPayload($this->payload);
    $productOptions = [];
    foreach ($sourceOptions as $idx => $opt) {
        $name = $opt['name'] ?? null;
        if (!$name) continue;
        $vals = [];
        foreach (($opt['values'] ?? []) as $v) {
            $sv = (string)$v;
            if ($sv !== '') $vals[] = ['name' => $sv];
        }
        $productOptions[] = array_filter([
            'name'     => $name,
            'position' => $idx + 1,
            'values'   => $vals,
        ], fn($v) => $v !== null && $v !== []);
    }

    $variantsForSet = [];
    foreach ($srcVariants as $rawKey => $sv) {
        $ckey = $this->canonKey($rawKey);
        $vm   = $mirrorByKey[$ckey] ?? null;
        if (!$vm || empty($vm->target_variant_gid)) continue;

        // Construim optionValues folosind numele/valorile din sursă (aliniate cu productOptions)
        $optionValues = $this->buildOptionValuesForProductSet($rawKey, $sourceOptions);

        $ident = [
            'id'           => $vm->target_variant_gid,
            'optionValues' => $optionValues,
        ];

        if (array_key_exists('sku', $sv))     { $ident['sku']     = $sv['sku']; }
        if (array_key_exists('barcode', $sv)) { $ident['barcode'] = ($sv['barcode'] === '' ? null : $sv['barcode']); }

        // Dacă avem ceva de setat (pe lângă id și optionValues), include această variantă
        if (count($ident) > 2) {
            $variantsForSet[] = $ident;
        }
    }

    if (!empty($variantsForSet)) {
        Log::info('productSet variants payload (identities)', array_map(fn($s) => [
            'id'      => $s['id'] ?? null,
            'sku'     => $s['sku'] ?? null,
            'barcode' => array_key_exists('barcode', $s) ? $s['barcode'] : '(not set)',
        ], $variantsForSet));

        // Folosim helperul local productSet() care gestionează erorile
        $this->productSet($target, [
            'id'             => $mirror->target_product_gid,
            'productOptions' => $productOptions,
            'variants'       => array_values($variantsForSet),
        ]);
    }

    // 3.35) Track quantity (InventoryItem.tracked) conform inventory_management din sursă
    $trackedWantedByKey = [];
    foreach ($srcVariants as $key => $sv) {
        $ckey = $this->canonKey($key);
        $vm   = $mirrorByKey[$ckey] ?? null;
        if (!$vm || empty($vm->target_variant_gid)) continue;
        // Preferăm starea din sursă (dacă am putut să o citim). Altfel, cădem pe payload.
        if (array_key_exists($ckey, $sourceTrackedByKey)) {
            $trackedWanted = (bool)$sourceTrackedByKey[$ckey];
            Log::debug('Track qty: using source tracked state', [
                'key' => $ckey,
                'tracked' => $trackedWanted,
            ]);
        } else {
            $present = (bool)($sv['inventory_management_present'] ?? false);
            if (!$present) {
                Log::info('Track qty: inventory_management missing in source payload → keep target as-is', [
                    'key' => $ckey,
                ]);
                $trackedWantedByKey[$ckey] = null; // necunoscut
                continue;
            }
            $imVal = strtolower((string)($sv['inventory_management'] ?? ''));
            $trackedWanted = ($imVal === 'shopify');
            Log::debug('Track qty: using payload inventory_management', [
                'key' => $ckey,
                'value' => $sv['inventory_management'] ?? null,
                'tracked' => $trackedWanted,
            ]);
        }
        $trackedWantedByKey[$ckey] = $trackedWanted;

        [$itemId, ] = $this->fetchInventoryItemAndLocations($target, $vm->target_variant_gid);
        if ($itemId !== null) {
            // 3.35.1) InventoryItem.tracked este suficient în versiunea actuală de API pentru a reflecta checkbox-ul în Admin.
            // Unele versiuni nu expun productVariantUpdate/productVariantsUpdate, deci evităm apelul care produce erori.
            $invMgmt = $trackedWanted ? 'SHOPIFY' : 'NOT_MANAGED';
            Log::debug('Track qty: skipping variant inventoryManagement mutation (unsupported on this API); relying on InventoryItem.tracked', [
                'key' => $ckey,
                'variant_gid' => $vm->target_variant_gid,
                'inventoryManagement_intended' => $invMgmt,
            ]);

            Log::debug('Track qty: applying', [
                'key' => $ckey,
                'inventory_management' => $sv['inventory_management'] ?? null,
                'tracked' => $trackedWanted,
            ]);
            $this->inventoryItemUpdate($target, $itemId, $trackedWanted);
        } else {
            Log::warning('Track qty: no inventoryItemId found', [
                'key' => $ckey,
                'variant_gid' => $vm->target_variant_gid,
            ]);
        }
    }

    // 3.4) Inventory qty – absolut (GraphQL inventorySetQuantities)
    foreach ($srcVariants as $key => $sv) {
        $ckey = $this->canonKey($key);
        $vm   = $mirrorByKey[$ckey] ?? null;
        if (!$vm || empty($vm->target_variant_gid)) continue;

        // dacă payload-ul sursă indică explicit tracking off, sărim setarea de cantități
        $trackedWanted = $trackedWantedByKey[$ckey] ?? null;
        if ($trackedWanted === false) {
            Log::info('Skip inventorySetQuantities: tracking OFF for variant', [
                'key' => $ckey,
            ]);
            continue;
        }

        if (isset($sv['inventory_quantity']) && $sv['inventory_quantity'] !== null) {
            [$itemId, $locIds] = $this->fetchInventoryItemAndLocations($target, $vm->target_variant_gid);
            $this->inventorySetQuantities($target, $itemId, $locIds, (int)$sv['inventory_quantity']);
        }
    }

    // 3.5) Persistă fingerprint-urile NOI pentru economic fields
    foreach ($srcVariants as $key => $sv) {
        $ckey = $this->canonKey($key);
        if (!isset($mirrorByKey[$ckey])) continue;
        $m = $mirrorByKey[$ckey];

        if ((string)$m->variant_fingerprint !== (string)$sv['variant_fingerprint']) {
            $m->variant_fingerprint = $sv['variant_fingerprint'];
            $m->save();
        }
    }

    // === 4) Snapshot nou ===
    $newSnap = $this->normalizeProductSnapshot($this->payload);
    $mirror->last_snapshot = $newSnap;
    $mirror->save();

    Log::info('Replicate update done', [
        'target_shop' => $target->domain,
        'target_gid'  => $mirror->target_product_gid,
    ]);
}




    private function gql(Shop $shop, string $query, array $variables = []): array
    {
        $version  = '2025-01';
        $endpoint = "https://{$shop->domain}/admin/api/{$version}/graphql.json";

        $resp = Http::withHeaders([
            'X-Shopify-Access-Token' => $shop->access_token,
            'Content-Type'           => 'application/json',
        ])->post($endpoint, ['query' => $query, 'variables' => $variables]);

        $resp->throw();
        return $resp->json();
    }

    // === Product-level ===

    private function computeProductDiff(array $payload, array $lastSnapshot): array
    {
        $diff = [];

        // title
        $title = $payload['title'] ?? null;
        if ($title !== null && ($lastSnapshot['title'] ?? null) !== $title) {
            $diff['title'] = $title;
        }

        // body_html -> descriptionHtml
        $desc = $payload['body_html'] ?? null;
        if ($desc !== null && ($lastSnapshot['body_html'] ?? null) !== $desc) {
            $diff['descriptionHtml'] = $desc;
        }

        // vendor
        $vendor = $payload['vendor'] ?? null;
        if ($vendor !== null && ($lastSnapshot['vendor'] ?? null) !== $vendor) {
            $diff['vendor'] = $vendor;
        }

        // product_type -> productType
        $ptype = $payload['product_type'] ?? null;
        if ($ptype !== null && ($lastSnapshot['product_type'] ?? null) !== $ptype) {
            $diff['productType'] = $ptype;
        }

        // tags (string comma) -> set
        if (array_key_exists('tags', $payload)) {
            $newTags = $this->splitTags($payload['tags'] ?? '');
            $oldTags = $this->splitTags($lastSnapshot['tags'] ?? '');
            if ($newTags !== $oldTags) {
                $diff['tags'] = $newTags; // GraphQL acceptă array de stringuri
            }
        }

        // status (REST: active/draft/archived) -> enum
        if (array_key_exists('status', $payload)) {
            $map = ['active' => 'ACTIVE', 'draft' => 'DRAFT', 'archived' => 'ARCHIVED'];
            $new = $map[strtolower((string)$payload['status'])] ?? null;
            $old = $map[strtolower((string)($lastSnapshot['status'] ?? ''))] ?? null;
            if ($new && $new !== $old) {
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

        Log::info('productUpdate payload', ['target' => $shop->domain, 'fields' => array_keys($patch)]);
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
                    Log::info('REST deleted ProductImage', [
                        'target'  => $shop->domain,
                        'product' => $productId,
                        'image'   => $imageId,
                    ]);
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
     * Normalizează variantele sursă într-o hartă key => payload normalizat
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

            $out[$key] = $norm;
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

    private function productVariantDelete(Shop $shop, string $variantGid): void
    {
        $m = <<<'GQL'
        mutation productVariantDelete($id: ID!) {
          productVariantDelete(id: $id) {
            deletedProductVariantId
            userErrors { field message code }
          }
        }
        GQL;

        $res = $this->gql($shop, $m, ['id' => $variantGid]);
        if (!empty($res['errors'])) {
            Log::error('productVariantDelete top-level errors', ['target'=>$shop->domain, 'errors'=>$res['errors']]);
            throw new \RuntimeException('productVariantDelete errors: '.json_encode($res['errors']));
        }
        $ue = $res['data']['productVariantDelete']['userErrors'] ?? [];
        if (!empty($ue)) {
            Log::error('productVariantDelete userErrors', ['target'=>$shop->domain, 'userErrors'=>$ue]);
            throw new \RuntimeException('productVariantDelete userErrors: '.json_encode($ue));
        }
    }

    /**
     * Bulk create variants for update flow using ProductVariantsBulkCreate.
     * Returns map: canonKey => ['variant_gid'=>..., 'inventory_item_gid'=>...]
     */
    private function productVariantsBulkCreateForUpdate(Shop $shop, string $productGid, array $toCreate, array $srcOptions): array
    {
        if (empty($toCreate)) return [];

        // Build ProductVariantsBulkInput[]
        $variantsInput = [];
        $optionNames = array_map(fn($o) => (string)($o['name'] ?? ''), $srcOptions);

        foreach ($toCreate as $rawKey => $sv) {
            // optionValues [{name, optionName}]
            $ov = [];
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
            ], fn($x) => $x !== null && $x !== []);
        }

        $mutation = <<<'GQL'
        mutation CreateVariants($productId: ID!, $variants: [ProductVariantsBulkInput!]!, $strategy: ProductVariantsBulkCreateStrategy!) {
          productVariantsBulkCreate(productId: $productId, variants: $variants, strategy: REMOVE_STANDALONE_VARIANT) {
            productVariants { id selectedOptions { name value } inventoryItem { id } }
            userErrors { field message }
          }
        }
        GQL;

        $res = $this->gql($shop, $mutation, [
            'productId' => $productGid,
            'variants'  => $variantsInput,
            'strategy'  => 'REMOVE_STANDALONE_VARIANT',
        ]);

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

        // Map back to canonical key
        $out = [];
        foreach ($created as $node) {
            $sel = $node['selectedOptions'] ?? [];
            $byName = [];
            foreach ($sel as $so) { $byName[$this->canonOptName((string)($so['name'] ?? ''))] = $this->canonOptVal((string)($so['value'] ?? '')); }
            $parts = [];
            foreach ($optionNames as $n) { $parts[] = $this->canonOptName($n).'='.($byName[$this->canonOptName($n)] ?? ''); }
            $ckey = implode('|', $parts);
            $out[$ckey] = [
                'variant_gid'        => $node['id'] ?? null,
                'inventory_item_gid' => $node['inventoryItem']['id'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Încărcă map-ul de mirror pentru variante: source_options_key => VariantMirror
     */
    private function loadVariantMirrorMap(ProductMirror $pm): array
    {
        $rows = VariantMirror::where('product_mirror_id', $pm->id)->get();
        $map = [];
        foreach ($rows as $row) {
            $key = $this->canonKey($row->source_options_key ?? '');
            $map[$key] = $row;
        }
        return $map;
    }

    /**
     * Calculează difful: care variante se creează / se actualizează / se șterg (doar raport)
     */
    private function computeVariantDiff(array $payload, ProductMirror $pm, array $srcOptions): array
    {
        $srcVariants    = $this->normalizeSourceVariants($payload, $srcOptions);
        $mirrorByKey    = $this->loadVariantMirrorMap($pm);

        $toCreate = [];
        $toUpdate = [];
        $toDelete = [];

        // Create/Update
        foreach ($srcVariants as $key => $sv) {
            $mirror = $mirrorByKey[$key] ?? null;
            if (!$mirror) {
                $toCreate[$key] = $sv;
                continue;
            }

            $oldFp = (string)($mirror->variant_fingerprint ?? '');
            if ($oldFp !== (string)$sv['variant_fingerprint']) {
                $toUpdate[$key] = [
                    'src'    => $sv,
                    'mirror' => $mirror,
                ];
            }
            // inventarul îl tratăm separat (M5), deci îl ignorăm în acest diff
            unset($mirrorByKey[$key]); // consumat
        }

        // Delete (ce a rămas în mirror și nu mai e în sursă)
        foreach ($mirrorByKey as $key => $mirror) {
            $toDelete[$key] = $mirror;
        }

        return compact('toCreate','toUpdate','toDelete');
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

    // Set/reorder/rename/add/remove product options (schema nouă). În unele versiuni poate lipsi.
    private function productOptionsSet(Shop $shop, string $productGid, array $options, string $variantStrategy = 'LEAVE_AS_IS'): void
    {
        $m = <<<'GQL'
        mutation setOptions($productId: ID!, $options: [ProductOptionInput!]!, $variantStrategy: ProductOptionSetVariantStrategy) {
          productOptionsSet(productId: $productId, options: $options, variantStrategy: $variantStrategy) {
            product { id }
            userErrors { field message code }
          }
        }
        GQL;

        // Convertim shape-ul nostru (OptionCreateInput) la OptionInput așteptat de Set
        $opts = [];
        foreach ($options as $opt) {
            $values = [];
            foreach (($opt['values'] ?? []) as $v) {
                // Acceptă atât string cât și {name}; normalizăm la {name}
                if (is_array($v) && isset($v['name'])) {
                    $values[] = ['name' => (string)$v['name']];
                } else {
                    $values[] = ['name' => (string)$v];
                }
            }
            $opts[] = array_filter([
                'name'     => $opt['name'] ?? null,
                'position' => $opt['position'] ?? null,
                'values'   => $values,
            ], fn($v) => $v !== null && $v !== []);
        }

        $res = $this->gql($shop, $m, [
            'productId'       => $productGid,
            'options'         => $opts,
            'variantStrategy' => $variantStrategy,
        ]);

        // Dacă API-ul nu are mutația/enum-ul, Shopify pune eroarea în top-level errors
        if (!empty($res['errors'])) {
            Log::error('productOptionsSet top-level errors', ['target' => $shop->domain, 'errors' => $res['errors']]);
            // Lasă apelantul să decidă fallback; ridicăm excepție clară
            throw new \RuntimeException('productOptionsSet top-level errors: ' . json_encode($res['errors']));
        }
        $ue = $res['data']['productOptionsSet']['userErrors'] ?? [];
        if (!empty($ue)) {
            Log::error('productOptionsSet userErrors', ['target' => $shop->domain, 'userErrors' => $ue]);
            throw new \RuntimeException('productOptionsSet userErrors: ' . json_encode($ue));
        }
    }

    // --- Target variants: citire și mapare pe cheie canonică ---
    private function fetchTargetVariantsMap(Shop $shop, string $productGid, array $optionNames): array
    {
        $q = <<<'GQL'
        query($id: ID!) {
        product(id: $id) {
            variants(first: 250) {
            nodes {
                id
                selectedOptions { name value }
            }
            }
        }
        }
        GQL;

        $r = $this->gql($shop, $q, ['id' => $productGid]);
        $nodes = $r['data']['product']['variants']['nodes'] ?? [];

        $map = [];
        foreach ($nodes as $v) {
            // construim cheia canonică din selectedOptions în ORDINEA din $optionNames
            $sel = $v['selectedOptions'] ?? [];
            $byName = [];
            foreach ($sel as $so) {
                $byName[$this->canonOptName((string)($so['name'] ?? ''))] = $this->canonOptVal((string)($so['value'] ?? ''));
            }
            $parts = [];
            foreach ($optionNames as $name) {
                $parts[] = $this->canonOptName($name).'='.($byName[$this->canonOptName($name)] ?? '');
            }
            $key = implode('|', $parts);
            $map[$key] = $v['id'] ?? null; // target variant GID
        }
        return $map;
    }

    // Citește de pe produsul sursă starea tracked pentru fiecare variantă și mapează pe cheia canonică
    private function fetchTrackedMapByKey(Shop $shop, string $productGid, array $optionNames): array
    {
        $q = <<<'GQL'
        query($id: ID!) {
          product(id: $id) {
            variants(first: 250) {
              nodes {
                id
                selectedOptions { name value }
                inventoryItem { id tracked }
              }
            }
          }
        }
        GQL;

        $r = $this->gql($shop, $q, ['id' => $productGid]);
        $nodes = $r['data']['product']['variants']['nodes'] ?? [];

        $map = [];
        foreach ($nodes as $v) {
            $sel = $v['selectedOptions'] ?? [];
            $byName = [];
            foreach ($sel as $so) {
                $byName[$this->canonOptName((string)($so['name'] ?? ''))] = $this->canonOptVal((string)($so['value'] ?? ''));
            }
            $parts = [];
            foreach ($optionNames as $name) {
                $parts[] = $this->canonOptName($name).'='.($byName[$this->canonOptName($name)] ?? '');
            }
            $key = implode('|', $parts);
            $map[$key] = (bool)($v['inventoryItem']['tracked'] ?? false);
        }
        return $map; // key => tracked(bool)
    }

    // --- Asigură existența VariantMirror pe cheile comune (lazy bootstrap) ---
private function ensureVariantMirrors(
    ProductMirror $pm,
    Shop $target,
    array $srcOptions,
    array $srcVariantsByKey
): void {
    $optionNames = array_map(fn($o) => $o['name'], $srcOptions);

    // key => targetVariantGid (din Shopify target)
    $targetMap = $this->fetchTargetVariantsMap($target, $pm->target_product_gid, $optionNames);

    foreach ($srcVariantsByKey as $rawKey => $sv) {
        $ckey = $this->canonKey($rawKey);
        $srcVarId = $sv['source_variant_id'] ?? null;

        // 1) caută întâi după (product_mirror_id, source_variant_id) – e unică
        $vm = null;
        if ($srcVarId) {
            $vm = VariantMirror::where('product_mirror_id', $pm->id)
                ->where('source_variant_id', $srcVarId)
                ->first();
        }

        // 2) dacă nu există după ID, încearcă după cheie canonică
        if (!$vm) {
            $vm = VariantMirror::where('product_mirror_id', $pm->id)
                ->where('source_options_key', $ckey)
                ->first();
        }

        $targetGid = $targetMap[$ckey] ?? null;
        if (!$vm) {
            // nu avem rând – creăm doar dacă putem mappa la o variantă din target
            if (!$targetGid) {
                // nu există varianta în target; o vom crea într-o etapă viitoare
                \Log::info('ensureVariantMirrors: skip create (no target variant)', [
                    'product_mirror_id' => $pm->id,
                    'key' => $ckey,
                ]);
                continue;
            }

            VariantMirror::create([
                'product_mirror_id'     => $pm->id,
                'source_options_key'    => $ckey,
                'source_variant_id'     => $srcVarId,
                'target_variant_gid'    => $targetGid,
                'variant_fingerprint'   => $sv['variant_fingerprint'] ?? null,
                'inventory_fingerprint' => $sv['inventory_fingerprint'] ?? null,
            ]);

            \Log::info('ensureVariantMirrors: created', [
                'product_mirror_id' => $pm->id,
                'key'               => $ckey,
                'target_gid'        => $targetGid,
            ]);
        } else {
            // există – facem backfill/normalizare fără să stricăm fingerprint-urile existente
            $dirty = false;

            if ($vm->source_options_key !== $ckey) {
                $vm->source_options_key = $ckey;
                $dirty = true;
            }

            if (empty($vm->target_variant_gid) && $targetGid) {
                $vm->target_variant_gid = $targetGid;
                $dirty = true;
            }

            // nu suprascriem fingerprint-urile dacă sunt deja puse;
            // dar dacă lipsesc (null/empty) le completăm
            if (empty($vm->variant_fingerprint) && !empty($sv['variant_fingerprint'])) {
                $vm->variant_fingerprint = $sv['variant_fingerprint'];
                $dirty = true;
            }
            if (empty($vm->inventory_fingerprint) && !empty($sv['inventory_fingerprint'])) {
                $vm->inventory_fingerprint = $sv['inventory_fingerprint'];
                $dirty = true;
            }

            if ($dirty) {
                $vm->save();
                \Log::info('ensureVariantMirrors: updated', [
                    'product_mirror_id' => $pm->id,
                    'key'               => $ckey,
                    'target_gid'        => $vm->target_variant_gid,
                ]);
            }
        }
    }
}



    // --- Construiește input pentru BulkUpdate din payload-ul sursă normalizat ---
    private function buildVariantUpdateInput(string $targetVariantGid, array $src): array
    {
        return array_filter([
            'id'              => $targetVariantGid,
            'price'           => isset($src['price']) ? (string)$src['price'] : null,
            'compareAtPrice'  => isset($src['compare_at_price']) ? (string)$src['compare_at_price'] : null,
            'taxable'         => isset($src['taxable']) ? (bool)$src['taxable'] : null,
            'inventoryPolicy' => isset($src['inventory_policy'])
                ? (strtolower($src['inventory_policy']) === 'continue' ? 'CONTINUE' : 'DENY')
                : null,
            // dacă vrei și greutatea (acceptată în bulk), poți păstra:
            // 'weight'      => $src['weight'] ?? null,
            // 'weightUnit'  => $this->mapWeightUnit($src['weight_unit'] ?? null),
        ], fn($v) => $v !== null);
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

        \Log::debug('inventoryItemUpdate call', [
            'target'  => $shop->domain,
            'item_id' => $inventoryItemId,
            'tracked' => $tracked,
        ]);

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

        \Log::debug('inventoryItemUpdate OK', [
            'target'  => $shop->domain,
            'item_id' => $inventoryItemId,
            'tracked' => $tracked,
        ]);
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
