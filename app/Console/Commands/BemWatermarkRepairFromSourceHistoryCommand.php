<?php

namespace App\Console\Commands;

use App\Models\ProductMirror;
use App\Models\Shop;
use App\Services\Shopify\BemWatermark\BemImageIdentityService;
use App\Services\Shopify\BemWatermark\BemProductWatermarkMetafieldService;
use App\Services\Shopify\BemWatermark\BemShopifyGraphqlClient;
use App\Services\Shopify\BemWatermark\BemShopifyStagedUploadService;
use App\Services\Shopify\BemWatermark\BemWatermarkImageProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BemWatermarkRepairFromSourceHistoryCommand extends Command
{
    protected $signature = 'bem-watermark:repair-from-source-history
        {source_product_id : Source Shopify product legacy id}
        {--shop= : Optional target shop domain or id. If omitted, repairs backup and all BEM target mirrors}
        {--from-live-source : Use current source product images as clean originals instead of prod.watermarked history}
        {--dry-run : Show what would be repaired without replacing media}';

    protected $description = 'Repair BEM images from source prod.watermarked history or current live source images.';

    public function handle(
        BemShopifyGraphqlClient $graphql,
        BemShopifyStagedUploadService $uploadService,
        BemWatermarkImageProcessor $imageProcessor,
        BemProductWatermarkMetafieldService $metafieldService,
        BemImageIdentityService $identity
    ): int {
        $sourceProductId = (int) $this->argument('source_product_id');
        $dryRun = (bool) $this->option('dry-run');

        $mirrors = ProductMirror::where('source_product_id', $sourceProductId)->get();
        if ($mirrors->isEmpty()) {
            $this->error('No product mirrors found for source product '.$sourceProductId);
            return self::FAILURE;
        }

        $sourceShop = Shop::find((int) $mirrors->first()->source_shop_id);
        if (!$sourceShop) {
            $this->error('Source shop not found for product '.$sourceProductId);
            return self::FAILURE;
        }

        $sourceProductGid = 'gid://shopify/Product/'.$sourceProductId;
        $sourceProduct = $this->fetchProductWithWatermarked($graphql, $sourceShop, $sourceProductGid);
        $sourceTitle = (string) ($sourceProduct['title'] ?? $sourceProductId);
        $currentImages = $this->option('from-live-source')
            ? $this->currentImagesFromLiveSource($sourceProduct, $identity)
            : $this->currentImagesFromSourceHistory($sourceProduct, $identity);

        $backupDomain = strtolower((string) config('features.bem_watermark_sync.backup_shop_domain'));
        $backupMirror = $mirrors->first(function (ProductMirror $mirror) use ($backupDomain) {
            $shop = Shop::find($mirror->target_shop_id);

            return $shop && strtolower((string) $shop->domain) === $backupDomain;
        });

        if (!$backupMirror || !$backupMirror->target_product_gid) {
            $this->error('Backup mirror not found for product '.$sourceProductId);
            return self::FAILURE;
        }

        $backupShop = Shop::find((int) $backupMirror->target_shop_id);
        if (!$backupShop) {
            $this->error('Backup shop not found for product '.$sourceProductId);
            return self::FAILURE;
        }

        $targetMirrors = $this->targetMirrors($mirrors, $backupShop);
        if ($this->option('shop')) {
            $targetMirrors = $this->filterTargetMirrors($targetMirrors, (string) $this->option('shop'));
        }

        $this->info(sprintf(
            'source=%s product=%d current_images=%d dry_run=%s',
            $sourceShop->domain,
            $sourceProductId,
            count($currentImages),
            $dryRun ? 'true' : 'false'
        ));
        $this->line('source_images_mode='.($this->option('from-live-source') ? 'live_source' : 'prod_watermarked_history'));

        if ($dryRun) {
            $this->line(sprintf('backup=%s images=%d', $backupShop->domain, count($currentImages)));
            foreach ($targetMirrors as $mirror) {
                $shop = Shop::find($mirror->target_shop_id);
                $this->line(sprintf('target=%s images=%d', $shop?->domain, count($currentImages)));
            }

            return self::SUCCESS;
        }

        $tempPaths = [];

        try {
            $backupUploaded = $uploadService->replaceProductImagesFromUrls(
                $backupShop,
                $backupMirror->target_product_gid,
                $currentImages
            );
            $backupImages = $this->imagesFromBackupUpload($currentImages, $backupUploaded);
            $this->updateMirrorSnapshot($backupMirror, $backupImages, $identity);
            $this->info(sprintf('backup repaired %s images=%d', $backupShop->domain, count($backupImages)));

            $sourcePayload = $this->sourceMetafieldPayload(
                $sourceShop,
                $sourceProductGid,
                $sourceProductId,
                $sourceProduct,
                $currentImages
            );
            $metafieldService->update($sourceShop, $sourceProductGid, $sourcePayload);

            foreach ($targetMirrors as $mirror) {
                $target = Shop::find($mirror->target_shop_id);
                if (!$target || !$mirror->target_product_gid) {
                    continue;
                }

                $processedResult = $imageProcessor->process($target, $sourceTitle, $backupImages);
                $processedImages = $processedResult['processed'];
                $tempPaths = array_merge($tempPaths, $processedResult['temp_paths']);

                $uploadedImages = $uploadService->replaceProductImages(
                    $target,
                    $mirror->target_product_gid,
                    $processedImages
                );

                $finalImages = $this->mergeUploadedImages($processedImages, $uploadedImages);
                $metafieldService->update($target, $mirror->target_product_gid, [
                    'source_shop' => $backupShop->domain,
                    'source_product_id' => $backupMirror->target_product_id,
                    'source_product_gid' => $backupMirror->target_product_gid,
                    'target_shop' => $target->domain,
                    'target_product_id' => $mirror->target_product_id,
                    'target_product_gid' => $mirror->target_product_gid,
                    'updated_at' => now()->toIso8601String(),
                    'dry_run' => false,
                    'repair_source_product_id' => $sourceProductId,
                    'images' => $this->metafieldImages($finalImages),
                ]);

                $this->updateMirrorSnapshot($mirror, $finalImages, $identity);
                $this->info(sprintf('target repaired %s images=%d', $target->domain, count($finalImages)));
            }
        } catch (\Throwable $e) {
            Log::error('BEM repair from source history failed', [
                'source_product_id' => $sourceProductId,
                'error' => $e->getMessage(),
            ]);
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $imageProcessor->cleanup($tempPaths);
        }

        return self::SUCCESS;
    }

    private function fetchProductWithWatermarked(BemShopifyGraphqlClient $graphql, Shop $shop, string $productGid): array
    {
        $query = <<<'GQL'
        query BemRepairProduct($id: ID!) {
          product(id: $id) {
            id
            legacyResourceId
            title
            images(first: 250) {
              nodes {
                id
                url
                altText
              }
            }
            metafield(namespace: "prod", key: "watermarked") {
              value
            }
          }
        }
        GQL;

        $response = $graphql->request($shop, $query, ['id' => $productGid]);
        $product = $response['data']['product'] ?? null;
        if (!$product) {
            throw new \RuntimeException('Product not found: '.$productGid);
        }

        $product['watermarked_payload'] = json_decode($product['metafield']['value'] ?? 'null', true);

        return $product;
    }

    private function currentImagesFromSourceHistory(array $sourceProduct, BemImageIdentityService $identity): array
    {
        $payload = $sourceProduct['watermarked_payload'] ?? null;
        $history = is_array($payload) ? (array) ($payload['images'] ?? []) : [];
        if (empty($history)) {
            throw new \RuntimeException('Source prod.watermarked has no image history');
        }

        $byUrl = [];
        $byFilename = [];
        foreach ($history as $image) {
            $watermarkedUrl = $identity->canonicalUrl($image['watermarked_url'] ?? null);
            if ($watermarkedUrl) {
                $byUrl[$watermarkedUrl] = $image;
            }

            foreach ([
                $image['filename'] ?? null,
                $identity->filenameFromUrl($image['watermarked_url'] ?? null),
            ] as $filename) {
                if ($filename) {
                    $byFilename[strtolower($filename)] = $image;
                }
            }
        }

        $current = [];
        foreach (($sourceProduct['images']['nodes'] ?? []) as $index => $node) {
            $url = (string) ($node['url'] ?? '');
            $filename = $identity->filenameFromUrl($url);
            $historyImage = $byUrl[$identity->canonicalUrl($url)] ?? null;
            if (!$historyImage && $filename) {
                $historyImage = $byFilename[strtolower($filename)] ?? null;
            }

            if (!$historyImage || empty($historyImage['source_url'])) {
                throw new \RuntimeException('Could not map source image to prod.watermarked history: '.$url);
            }

            $current[] = [
                'position' => (int) ($historyImage['position'] ?? ($index + 1)),
                'source_url' => $historyImage['source_url'],
                'source_watermarked_url' => $url,
                'watermarked_url' => $url,
                'filename' => $historyImage['filename'] ?? $filename,
                'original_extension' => $historyImage['original_extension']
                    ?? $identity->extensionFromUrl($historyImage['source_url'] ?? null),
                'alt' => $node['altText'] ?? $sourceProduct['title'] ?? null,
                'status' => 'completed',
            ];
        }

        if (empty($current)) {
            foreach ($history as $index => $historyImage) {
                $sourceUrl = $historyImage['source_url'] ?? null;
                if (!$sourceUrl) {
                    continue;
                }

                if ($identity->isWatermarkedUrl($sourceUrl)) {
                    throw new \RuntimeException('Source history contains watermarked source_url at position '.($index + 1));
                }

                $current[] = [
                    'position' => (int) ($historyImage['position'] ?? ($index + 1)),
                    'source_url' => $sourceUrl,
                    'source_watermarked_url' => $historyImage['watermarked_url'] ?? null,
                    'watermarked_url' => $historyImage['watermarked_url'] ?? null,
                    'filename' => $historyImage['filename'] ?? $identity->filenameFromUrl($historyImage['watermarked_url'] ?? null),
                    'original_extension' => $historyImage['original_extension']
                        ?? $identity->extensionFromUrl($sourceUrl),
                    'alt' => $historyImage['alt'] ?? $sourceProduct['title'] ?? null,
                    'status' => 'completed',
                ];
            }

            if (!empty($current)) {
                Log::warning('BEM repair using full source history because source product has no current images', [
                    'source_product_id' => $sourceProduct['legacyResourceId'] ?? null,
                    'images_count' => count($current),
                ]);
            }
        }

        if (empty($current)) {
            throw new \RuntimeException('Source product has no current images and no clean source history');
        }

        return $current;
    }

    private function currentImagesFromLiveSource(array $sourceProduct, BemImageIdentityService $identity): array
    {
        $current = [];

        foreach (($sourceProduct['images']['nodes'] ?? []) as $index => $node) {
            $url = (string) ($node['url'] ?? '');
            if (!$url) {
                continue;
            }

            if ($identity->isWatermarkedUrl($url)) {
                throw new \RuntimeException('Live source image is already watermarked at position '.($index + 1).': '.$url);
            }

            $current[] = [
                'position' => $index + 1,
                'source_url' => $url,
                'source_watermarked_url' => null,
                'watermarked_url' => null,
                'filename' => $identity->filenameFromUrl($url),
                'original_extension' => $identity->extensionFromUrl($url),
                'alt' => $node['altText'] ?? $sourceProduct['title'] ?? null,
                'status' => 'completed',
            ];
        }

        if (empty($current)) {
            throw new \RuntimeException('Source product has no current live images');
        }

        Log::warning('BEM repair using current live source images as clean originals', [
            'source_product_id' => $sourceProduct['legacyResourceId'] ?? null,
            'images_count' => count($current),
        ]);

        return $current;
    }

    private function imagesFromBackupUpload(array $currentImages, array $backupUploaded): array
    {
        $images = [];
        foreach ($currentImages as $index => $currentImage) {
            $uploaded = $backupUploaded[$index] ?? [];
            $backupUrl = $uploaded['uploaded_url'] ?? null;
            if (!$backupUrl) {
                throw new \RuntimeException('Backup upload did not return image URL at index '.$index);
            }

            $images[] = [
                'position' => $currentImage['position'],
                'source_url' => $backupUrl,
                'original_source_url' => $currentImage['source_url'],
                'original_extension' => $currentImage['original_extension'],
                'alt' => $currentImage['alt'] ?? null,
                'status' => 'completed',
            ];
        }

        return $images;
    }

    private function sourceMetafieldPayload(
        Shop $sourceShop,
        string $sourceProductGid,
        int $sourceProductId,
        array $sourceProduct,
        array $currentImages
    ): array {
        return [
            'source_shop' => $sourceShop->domain,
            'source_product_id' => $sourceProductId,
            'source_product_gid' => $sourceProductGid,
            'target_shop' => $sourceShop->domain,
            'target_product_id' => $sourceProductId,
            'target_product_gid' => $sourceProductGid,
            'updated_at' => now()->toIso8601String(),
            'dry_run' => false,
            'repair_source_product_id' => $sourceProductId,
            'images' => array_values(array_map(static fn ($image) => [
                'position' => $image['position'] ?? null,
                'source_url' => $image['source_url'] ?? null,
                'watermarked_url' => $image['source_watermarked_url'] ?? ($image['watermarked_url'] ?? null),
                'filename' => $image['filename'] ?? null,
                'original_extension' => $image['original_extension'] ?? null,
                'status' => 'completed',
            ], $currentImages)),
            'deleted_from_previous_count' => max(0, count($sourceProduct['watermarked_payload']['images'] ?? []) - count($currentImages)),
        ];
    }

    private function mergeUploadedImages(array $processedImages, array $uploadedImages): array
    {
        $uploadedByPosition = [];
        foreach ($uploadedImages as $uploaded) {
            $uploadedByPosition[(int) ($uploaded['position'] ?? 0)] = $uploaded;
        }

        foreach ($processedImages as $index => $processed) {
            $position = (int) ($processed['position'] ?? 0);
            if (isset($uploadedByPosition[$position])) {
                $processedImages[$index] = array_merge($processed, $uploadedByPosition[$position]);
            }
        }

        return $processedImages;
    }

    private function metafieldImages(array $images): array
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

    private function targetMirrors($mirrors, Shop $backupShop)
    {
        $allowedDomains = array_values(array_filter(array_map(
            'strtolower',
            (array) config('features.bem_watermark_sync.target_shop_domains')
        )));

        if (empty($allowedDomains)) {
            $allowedDomains = array_values(array_filter(
                array_map('strtolower', array_keys((array) config('features.bem_watermark_sync.domain_aliases'))),
                static fn ($domain) => $domain !== strtolower((string) config('features.bem_watermark_sync.backup_shop_domain'))
            ));
        }

        return $mirrors->filter(function (ProductMirror $mirror) use ($backupShop, $allowedDomains) {
            if ((int) $mirror->target_shop_id === (int) $backupShop->id) {
                return false;
            }

            $shop = Shop::find($mirror->target_shop_id);
            if (!$shop || !$mirror->target_product_gid) {
                return false;
            }

            return in_array(strtolower((string) $shop->domain), $allowedDomains, true);
        })->values();
    }

    private function filterTargetMirrors($mirrors, string $shopOption)
    {
        return $mirrors->filter(function (ProductMirror $mirror) use ($shopOption) {
            $shop = Shop::find($mirror->target_shop_id);
            if (!$shop) {
                return false;
            }

            return is_numeric($shopOption)
                ? (int) $shop->id === (int) $shopOption
                : strtolower((string) $shop->domain) === strtolower($shopOption);
        })->values();
    }

    private function updateMirrorSnapshot(ProductMirror $mirror, array $images, BemImageIdentityService $identity): void
    {
        $snapshot = $mirror->last_snapshot ?? [];
        $snapshotImages = [];
        foreach (array_values($images) as $index => $image) {
            $url = $image['watermarked_url'] ?? $image['uploaded_url'] ?? $image['source_url'] ?? null;
            $snapshotImages[] = [
                'src' => $url,
                'src_canon' => $identity->canonicalUrl($url),
                'alt' => $image['alt'] ?? '',
                'position' => $index + 1,
            ];
        }

        $snapshot['images'] = $snapshotImages;
        $snapshot['images_fingerprint'] = $this->fingerprintImages($snapshotImages);
        $snapshot['bem_repaired_at'] = now()->toIso8601String();

        $mirror->last_snapshot = $snapshot;
        $mirror->save();
    }

    private function fingerprintImages(array $images): string
    {
        $pieces = [];
        foreach ($images as $image) {
            $pieces[] = ($image['src_canon'] ?? '').'|'.(string) ($image['alt'] ?? '');
        }

        return 'sha1:'.sha1(implode('||', $pieces));
    }
}
