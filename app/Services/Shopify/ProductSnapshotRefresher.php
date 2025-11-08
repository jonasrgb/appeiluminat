<?php

namespace App\Services\Shopify;

use App\Models\ProductMirror;
use App\Models\VariantMirror;
use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductSnapshotRefresher
{
    private const DEFAULT_API_VERSION = '2025-01';

    /**
     * Fetches the latest product payload from the source shop, normalises it, stores the snapshot on all mirrors,
     * and realigns VariantMirror rows with the currently existing variants from the target shop(s).
     *
     * @return array<string, mixed> summary payload (useful for artisan commands or controllers)
     */
    public function refresh(int $sourceShopId, int $sourceProductId): array
    {
        $sourceShop = Shop::findOrFail($sourceShopId);
        $rawProduct = $this->fetchSourceProductPayload($sourceShop, $sourceProductId);

        if (!$rawProduct) {
            throw new \RuntimeException("Source product {$sourceProductId} not found on {$sourceShop->domain}");
        }

        $snapshot    = $this->normalizeProductSnapshot($rawProduct);
        $sourceOpts  = $this->normalizeOptionsFromPayload($rawProduct);
        $sourceVars  = $this->normalizeSourceVariants($rawProduct, $sourceOpts);

        /** @var \Illuminate\Support\Collection<int, ProductMirror> $mirrors */
        $mirrors = ProductMirror::where('source_shop_id', $sourceShopId)
            ->where('source_product_id', $sourceProductId)
            ->get();

        $targetsSummary = [];

        foreach ($mirrors as $mirror) {
            $mirror->last_snapshot = $snapshot;
            $mirror->save();

            $targetShop = $mirror->target_shop_id ? Shop::find($mirror->target_shop_id) : null;
            if (!$targetShop || !$mirror->target_product_gid) {
                Log::warning('Snapshot refresh skipped variant alignment: missing target product mapping', [
                    'source_shop'        => $sourceShop->domain,
                    'source_product_id'  => $sourceProductId,
                    'product_mirror_id'  => $mirror->id,
                    'target_shop_id'     => $mirror->target_shop_id,
                ]);

                $targetsSummary[] = [
                    'product_mirror_id' => $mirror->id,
                    'target_shop_id'    => $mirror->target_shop_id,
                    'status'            => 'snapshot_refreshed_missing_target',
                ];
                continue;
            }

            try {
                $this->ensureVariantMirrors($mirror, $targetShop, $sourceOpts, $sourceVars);
                $status = 'snapshot_and_variants_refreshed';
            } catch (\Throwable $e) {
                Log::error('Snapshot refresh variant alignment failed', [
                    'source_shop'       => $sourceShop->domain,
                    'target_shop'       => $targetShop->domain,
                    'product_mirror_id' => $mirror->id,
                    'error'             => $e->getMessage(),
                ]);
                $status = 'snapshot_refreshed_variant_sync_failed';
            }

            $targetsSummary[] = [
                'product_mirror_id' => $mirror->id,
                'target_shop_id'    => $mirror->target_shop_id,
                'target_product_gid'=> $mirror->target_product_gid,
                'status'            => $status,
            ];
        }

        return [
            'source_shop_id'    => $sourceShopId,
            'source_product_id' => $sourceProductId,
            'mirror_count'      => $mirrors->count(),
            'targets'           => $targetsSummary,
        ];
    }

    private function fetchSourceProductPayload(Shop $shop, int $productId): ?array
    {
        $version = $shop->api_version ?: self::DEFAULT_API_VERSION;
        $endpoint = "https://{$shop->domain}/admin/api/{$version}/products/{$productId}.json";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $shop->access_token,
            'Content-Type'           => 'application/json',
        ])->get($endpoint);

        if ($response->status() === 404) {
            Log::warning('Snapshot refresh: source product not found', [
                'shop'       => $shop->domain,
                'product_id' => $productId,
            ]);
            return null;
        }

        $response->throw();

        return $response->json('product');
    }

    private function ensureVariantMirrors(
        ProductMirror $pm,
        Shop $target,
        array $srcOptions,
        array $srcVariantsByKey
    ): void {
        $optionNames = array_map(fn($opt) => $opt['name'], $srcOptions);
        $targetMap   = $this->fetchTargetVariantsMap($target, $pm->target_product_gid, $optionNames);

        foreach ($srcVariantsByKey as $rawKey => $sv) {
            $ckey     = $this->canonKey($rawKey);
            $srcVarId = $sv['source_variant_id'] ?? null;

            $vm = null;
            if ($srcVarId) {
                $vm = VariantMirror::where('product_mirror_id', $pm->id)
                    ->where('source_variant_id', $srcVarId)
                    ->first();
            }
            if (!$vm) {
                $vm = VariantMirror::where('product_mirror_id', $pm->id)
                    ->where('source_options_key', $ckey)
                    ->first();
            }

            $targetGid = $targetMap[$ckey] ?? null;
            if (!$vm) {
                if (!$targetGid) {
                    Log::info('Snapshot refresh: missing target variant for key', [
                        'product_mirror_id' => $pm->id,
                        'key'               => $ckey,
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

                Log::info('Snapshot refresh: created variant mirror', [
                    'product_mirror_id' => $pm->id,
                    'key'               => $ckey,
                    'target_gid'        => $targetGid,
                ]);
                continue;
            }

            $dirty = false;
            if ($vm->source_options_key !== $ckey) {
                $vm->source_options_key = $ckey;
                $dirty = true;
            }

            if (empty($vm->target_variant_gid) && $targetGid) {
                $vm->target_variant_gid = $targetGid;
                $dirty = true;
            }

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
                Log::info('Snapshot refresh: updated variant mirror', [
                    'product_mirror_id' => $pm->id,
                    'key'               => $ckey,
                    'target_gid'        => $vm->target_variant_gid,
                ]);
            }
        }
    }

    private function fetchTargetVariantsMap(Shop $shop, string $productGid, array $optionNames): array
    {
        $query = <<<'GQL'
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

        $response = $this->gql($shop, $query, ['id' => $productGid]);
        $nodes = $response['data']['product']['variants']['nodes'] ?? [];

        $map = [];
        foreach ($nodes as $variant) {
            $sel    = $variant['selectedOptions'] ?? [];
            $byName = [];
            foreach ($sel as $so) {
                $byName[$this->canonOptName((string)($so['name'] ?? ''))] = $this->canonOptVal((string)($so['value'] ?? ''));
            }

            $parts = [];
            foreach ($optionNames as $name) {
                $parts[] = $this->canonOptName($name).'='.($byName[$this->canonOptName($name)] ?? '');
            }
            $key = implode('|', $parts);
            $map[$key] = $variant['id'] ?? null;
        }

        return $map;
    }

    private function gql(Shop $shop, string $query, array $variables = []): array
    {
        $version  = $shop->api_version ?: self::DEFAULT_API_VERSION;
        $endpoint = "https://{$shop->domain}/admin/api/{$version}/graphql.json";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $shop->access_token,
            'Content-Type'           => 'application/json',
        ])->post($endpoint, ['query' => $query, 'variables' => $variables]);

        $response->throw();

        return $response->json();
    }

    private function normalizeProductSnapshot(array $payload): array
    {
        $images = $this->extractSourceImages($payload);
        $options = $this->normalizeOptionsFromPayload($payload);

        return [
            'id'                 => $payload['id'] ?? null,
            'title'              => $payload['title'] ?? null,
            'body_html'          => $payload['body_html'] ?? null,
            'vendor'             => $payload['vendor'] ?? null,
            'product_type'       => $payload['product_type'] ?? null,
            'tags'               => $payload['tags'] ?? null,
            'status'             => $payload['status'] ?? null,
            'images'             => $images,
            'images_fingerprint' => $this->fingerprintImages($images),
            'options'            => $options,
            'options_fingerprint'=> $this->optionsFingerprint($options),
        ];
    }

    private function extractSourceImages(array $src): array
    {
        $out = [];
        if (!empty($src['images']) && is_array($src['images'])) {
            foreach ($src['images'] as $index => $image) {
                $srcUrl = $image['src'] ?? null;
                $out[] = [
                    'id'        => $image['id'] ?? null,
                    'src'       => $srcUrl,
                    'src_canon' => $this->canonUrl($srcUrl),
                    'alt'       => $image['alt'] ?? '',
                    'position'  => (int)($image['position'] ?? ($index + 1)),
                ];
            }
        } elseif (!empty($src['media']) && is_array($src['media'])) {
            foreach ($src['media'] as $index => $media) {
                if (($media['media_content_type'] ?? '') !== 'IMAGE') {
                    continue;
                }
                $srcUrl = $media['preview_image']['src'] ?? null;
                $out[] = [
                    'src'       => $srcUrl,
                    'src_canon' => $this->canonUrl($srcUrl),
                    'alt'       => $media['alt'] ?? '',
                    'position'  => (int)($media['position'] ?? ($index + 1)),
                ];
            }
        }

        usort($out, fn($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        return $out;
    }

    private function normalizeOptionsFromPayload(array $payload): array
    {
        $out = [];
        $options = $payload['options'] ?? [];
        if (!is_array($options)) {
            return $out;
        }

        usort($options, function ($a, $b) {
            $pa = (int)($a['position'] ?? 0);
            $pb = (int)($b['position'] ?? 0);
            return $pa <=> $pb;
        });

        foreach ($options as $option) {
            $name = trim((string)($option['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $values = $option['values'] ?? [];
            if (!is_array($values)) {
                $values = [];
            }

            $seen = [];
            $vals = [];
            foreach ($values as $value) {
                $stringValue = (string)$value;
                if (!isset($seen[$stringValue])) {
                    $seen[$stringValue] = true;
                    $vals[] = $stringValue;
                }
            }

            $out[] = [
                'name'     => $name,
                'position' => (int)($option['position'] ?? null),
                'values'   => $vals,
            ];
        }

        return $out;
    }

    private function normalizeSourceVariants(array $payload, array $srcOptions): array
    {
        $out = [];
        $variants = $payload['variants'] ?? [];
        if (!is_array($variants)) {
            return $out;
        }

        $optionNames = array_map(fn($opt) => $opt['name'], $srcOptions);

        foreach ($variants as $variant) {
            $key = $this->variantKey($variant, $optionNames);

            $imageSrcCanon = null;
            if (!empty($variant['image_id']) && !empty($payload['images'])) {
                foreach ($payload['images'] as $image) {
                    if (($image['id'] ?? null) == $variant['image_id']) {
                        $imageSrcCanon = $this->canonUrl($image['src'] ?? null);
                        break;
                    }
                }
            }

            $inventoryManagementPresent = array_key_exists('inventory_management', $variant);
            $inventoryManagementValue   = $inventoryManagementPresent ? ($variant['inventory_management'] ?? null) : null;

            $normalised = [
                'source_variant_id'             => $variant['id'] ?? null,
                'sku'                           => $variant['sku'] ?? null,
                'barcode'                       => $variant['barcode'] ?? null,
                'price'                         => $variant['price'] ?? null,
                'compare_at_price'              => $variant['compare_at_price'] ?? null,
                'taxable'                       => $variant['taxable'] ?? null,
                'weight'                        => $variant['weight'] ?? null,
                'weight_unit'                   => $variant['weight_unit'] ?? null,
                'inventory_policy'              => $variant['inventory_policy'] ?? null,
                'inventory_management'          => $inventoryManagementValue,
                'inventory_management_present'  => $inventoryManagementPresent,
                'inventory_quantity'            => $variant['inventory_quantity'] ?? null,
                'image_src_canon'               => $imageSrcCanon,
            ];

            $normalised['variant_fingerprint']   = $this->variantFingerprint($normalised);
            $normalised['inventory_fingerprint'] = $this->inventoryFingerprint($normalised);

            $out[$key] = $normalised;
        }

        return $out;
    }

    private function variantKey(array $variant, array $optionNames): string
    {
        $parts = [];
        foreach ($optionNames as $index => $name) {
            $field = 'option'.($index + 1);
            $value = (string)($variant[$field] ?? '');
            $parts[] = $this->canonOptName($name).'='.$this->canonOptVal($value);
        }
        return implode('|', $parts);
    }

    private function variantFingerprint(array $variant): string
    {
        $pieces = [
            (string)($variant['price'] ?? ''),
            (string)($variant['compare_at_price'] ?? ''),
            (string)($variant['taxable'] ?? ''),
            (string)($variant['inventory_policy'] ?? ''),
        ];

        return 'sha1:'.sha1(implode('|', $pieces));
    }

    private function inventoryFingerprint(array $variant): string
    {
        $qty = (string)($variant['inventory_quantity'] ?? '');
        return 'sha1:'.sha1($qty);
    }

    private function fingerprintImages(array $images): string
    {
        $pieces = [];
        foreach ($images as $image) {
            $pieces[] = ($image['src_canon'] ?? '').'|'.(string)($image['alt'] ?? '');
        }
        return 'sha1:'.sha1(implode('||', $pieces));
    }

    private function optionsFingerprint(array $options): string
    {
        $pieces = [];
        foreach ($options as $option) {
            $name = mb_strtolower($option['name'] ?? '');
            $values = array_map(fn($value) => (string)$value, $option['values'] ?? []);
            $pieces[] = $name.':'.implode('|', $values);
        }
        return 'sha1:'.sha1(implode('||', $pieces));
    }

    private function canonUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || empty($parts['path'])) {
            return $url;
        }

        $host = strtolower($parts['host']);
        $scheme = ($parts['scheme'] ?? 'https') === 'http' ? 'http' : 'https';

        return $scheme.'://'.$host.$parts['path'];
    }

    private function canonOptName(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function canonOptVal(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function canonKey(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
