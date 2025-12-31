<?php

namespace App\Jobs;

use App\Models\ProductMediaProcess;
use App\Models\Shop;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class ApplyProductWatermark implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $shopDomain,
        public int $productId,
        public string $handle,
        public string $title,
        public array $images = [],
        public ?int $processId = null
    ) {}

    public function handle(): void
    {
        if ($this->productId <= 0 || empty($this->images)) {
            $this->markProcess(ProductMediaProcess::STATUS_SKIPPED, ['last_error' => 'No images provided']);
            return;
        }

        $shop = Shop::whereRaw('LOWER(domain) = ?', [strtolower($this->shopDomain)])->first();
        if (!$shop || empty($shop->access_token)) {
            Log::warning('Watermark skipped: missing shop credentials', [
                'shop' => $this->shopDomain,
                'product_id' => $this->productId,
            ]);
            $this->markProcess(ProductMediaProcess::STATUS_FAILED, [
                'last_error' => 'Missing shop credentials',
            ]);
            return;
        }

        $watermarkPath = $this->watermarkPathForShop($shop->domain);
        if (!$watermarkPath || !is_readable($watermarkPath)) {
            Log::warning('Watermark skipped: watermark file missing', [
                'shop' => $shop->domain,
                'product_id' => $this->productId,
            ]);
            $this->markProcess(ProductMediaProcess::STATUS_FAILED, [
                'last_error' => 'Watermark asset missing',
            ]);
            return;
        }

        $lockKey = sprintf(
            'watermark-lock:%s:%s',
            Str::slug($shop->domain),
            $this->productId
        );
        $lock = Cache::lock($lockKey, 900);
        if (!$lock->get()) {
            Log::info('Watermark job waiting for shop lock', [
                'shop' => $shop->domain,
                'product_id' => $this->productId,
            ]);

            $this->release(30);
            return;
        }

        try {
            $this->processWatermark($shop, $watermarkPath);
        } finally {
            optional($lock)->release();
        }
    }

    private function processWatermark(Shop $shop, string $watermarkPath): void
    {
        $tmpDir = storage_path('app/watermark/tmp');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $this->markProcess(ProductMediaProcess::STATUS_PROCESSING, [
            'started_at' => now(),
            'last_error' => null,
        ]);

        $imageManager = new ImageManager(new Driver());
        $processedImages = [];
        $tempPaths = [];

        Log::info('Watermark job started', [
            'shop' => $shop->domain,
            'product_id' => $this->productId,
            'images_expected' => count($this->images),
        ]);

        foreach ($this->images as $index => $image) {
            $src = $image['src'] ?? null;
            $imageId = $image['id'] ?? null;
            $position = $image['position'] ?? ($index + 1);
            if (!$src || !$imageId) {
                continue;
            }

            try {
                $downloaded = $this->downloadImage($src, $tmpDir);
                if (!$downloaded) {
                    Log::warning('Watermark download failed', [
                        'shop' => $shop->domain,
                        'image_src' => $src,
                    ]);
                    continue;
                }

                $processedPath = $this->applyWatermark($imageManager, $downloaded, $watermarkPath);
                if (!$processedPath) {
                    continue;
                }

                $processedPath = $this->enforceUploadLimit($processedPath, $imageManager, $shop->domain);

                $ext = pathinfo($processedPath, PATHINFO_EXTENSION) ?: 'jpg';
                $filename = $this->buildFilename($index, $ext);

                $processedImages[] = [
                    'image_id' => $imageId,
                    'path' => $processedPath,
                    'filename' => $filename,
                    'alt' => $this->title ?: $this->handle,
                    'position' => $position,
                    'mime' => $this->mimeFromExtension($ext),
                ];
                $tempPaths[] = $processedPath;
            } catch (\Throwable $e) {
                Log::error('Watermark processing exception', [
                    'shop' => $shop->domain,
                    'image_src' => $src,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (empty($processedImages)) {
            $this->markProcess(ProductMediaProcess::STATUS_SKIPPED, [
                'last_error' => 'No images processed',
            ]);
            return;
        }

        Log::info('Watermark images prepared', [
            'shop' => $shop->domain,
            'product_id' => $this->productId,
            'prepared_count' => count($processedImages),
        ]);

        $productGid = str_contains((string) $this->productId, 'gid://')
            ? (string) $this->productId
            : "gid://shopify/Product/{$this->legacyId($this->productId)}";

        $mediaIds = $this->fetchProductMediaIds($shop, $productGid);
        $stagedTargets = $this->stageUploads($shop, $processedImages);
        $newMediaInputs = [];

        foreach ($processedImages as $index => $processed) {
            $target = $stagedTargets[$index] ?? null;
            if (!$target) {
                Log::error('Missing staged upload target', [
                    'shop' => $shop->domain,
                    'product_id' => $this->productId,
                    'image_index' => $index,
                    'filename' => $processed['filename'],
                ]);
                continue;
            }

            $uploadOk = $this->uploadToStagedTarget($target, $processed['path'], $processed['filename'], $shop->domain);
            if (!$uploadOk) {
                continue;
            }

            $newMediaInputs[] = [
                'alt' => $processed['alt'],
                'mediaContentType' => 'IMAGE',
                'originalSource' => $target['resourceUrl'] ?? null,
            ];

            // Log::info('Watermark staged upload complete', [
            //     'shop' => $shop->domain,
            //     'product_id' => $this->productId,
            //     'image_index' => $index + 1,
            //     'resource_url' => $target['resourceUrl'] ?? null,
            // ]);
        }

        if (empty($newMediaInputs)) {
            Log::warning('Watermark skipped: no media inputs created', [
                'shop' => $shop->domain,
                'product_id' => $this->productId,
            ]);
            $this->markProcess(ProductMediaProcess::STATUS_FAILED, [
                'last_error' => 'Unable to stage uploads',
            ]);
            return;
        }

        $mutation = <<<'GQL'
        mutation ReplaceProductImages($productId: ID!, $mediaIds: [ID!]!, $media: [CreateMediaInput!]!) {
          deleteResult: productDeleteMedia(productId: $productId, mediaIds: $mediaIds) {
            deletedMediaIds
            mediaUserErrors { field message }
          }
          createResult: productCreateMedia(productId: $productId, media: $media) {
            media { id status }
            mediaUserErrors { field message }
          }
        }
        GQL;

        $graphqlResponse = $this->graphqlRequest($shop, $mutation, [
            'productId' => $productGid,
            'mediaIds' => $mediaIds,
            'media' => $newMediaInputs,
        ]);

        if (!$graphqlResponse || isset($graphqlResponse['errors'])) {
            Log::error('Watermark GraphQL mutation failed', [
                'shop' => $shop->domain,
                'product_id' => $this->productId,
                'response' => $graphqlResponse,
            ]);
            $this->markProcess(ProductMediaProcess::STATUS_FAILED, [
                'last_error' => 'GraphQL mutation failed',
            ]);
        } else {
            Log::info('Watermark GraphQL mutation completed', [
                'shop' => $shop->domain,
                'product_id' => $this->productId,
                'deleted' => $graphqlResponse['data']['deleteResult']['deletedMediaIds'] ?? [],
                'createErrors' => $graphqlResponse['data']['createResult']['mediaUserErrors'] ?? [],
            ]);

            $summary = $this->buildWatermarkSummary($shop, $productGid);
            if ($summary) {
                $this->updateWatermarkMetafield($shop, $productGid, $summary);
            }
        }

        foreach ($tempPaths as $path) {
            @unlink($path);
        }

        $this->markProcess(ProductMediaProcess::STATUS_COMPLETED, [
            'processed_count' => count($newMediaInputs),
            'completed_at' => now(),
        ]);

        Log::info('Watermark batch summary', [
            'shop' => $shop->domain,
            'product_id' => $this->productId,
            'processed_count' => count($processedImages),
            'uploaded_count' => count($newMediaInputs),
        ]);
    }

    private function downloadImage(string $url, string $dir): ?string
    {
        $response = Http::timeout(30)->get($url);
        if ($response->failed()) {
            return null;
        }

        $ext = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg';
        $path = $dir.'/original_'.Str::random(8).'.'.$ext;
        file_put_contents($path, $response->body());
        return $path;
    }

    private function applyWatermark(ImageManager $manager, string $sourcePath, string $watermarkPath): ?string
    {
        $image = $manager->read($sourcePath);
        $watermark = $manager->read($watermarkPath)->rotate(45, '00000000');

        $image->place(
            $watermark,
            position: 'center',
            opacity: 25
        );

        $processedPath = str_replace('original_', 'wm_', $sourcePath);
        $extension = strtolower(pathinfo($processedPath, PATHINFO_EXTENSION) ?: 'jpg');
        if (in_array($extension, ['jpg', 'jpeg', 'webp'])) {
            $image->save($processedPath, quality: 100);
        } else {
            $image->save($processedPath);
        }

        @unlink($sourcePath);
        return $processedPath;
    }

    private function enforceUploadLimit(string $path, ImageManager $manager, string $shopDomain): string
    {
        $maxBytes = 19 * 1024 * 1024; // ~19MB to stay under Shopify's 20MB limit
        $currentSize = filesize($path) ?: 0;

        if ($currentSize <= $maxBytes) {
            return $path;
        }

        try {
            $image = $manager->read($path);
            $quality = 90;
            $newPath = preg_replace('/\.\w+$/', '.jpg', $path) ?: $path.'.jpg';

            while ($quality >= 60) {
                $image->encode(new JpegEncoder(quality: $quality))->save($newPath);
                $newSize = filesize($newPath) ?: 0;

                if ($newSize <= $maxBytes) {
                    @unlink($path);
                    Log::warning('Watermark image downscaled for upload limit', [
                        'shop' => $shopDomain,
                        'original_size' => $currentSize,
                        'new_size' => $newSize,
                        'quality' => $quality,
                    ]);
                    return $newPath;
                }

                $quality -= 5;
            }

            Log::warning('Watermark image still exceeds upload limit after compression', [
                'shop' => $shopDomain,
                'size' => filesize($newPath) ?: 0,
            ]);

            @unlink($path);
            return $newPath;
        } catch (\Throwable $e) {
            Log::error('Watermark image compression failed', [
                'shop' => $shopDomain,
                'error' => $e->getMessage(),
            ]);
        }

        return $path;
    }

    private function buildFilename(int $index, string $extension): string
    {
        $slug = Str::slug($this->handle ?: 'product');
        $extension = ltrim($extension, '.');
        return "w_{$slug}_".($index + 1).'.'.$extension;
    }

    private function watermarkPathForShop(string $domain): ?string
    {
        $map = [
            'eiluminat.myshopify.com'    => 'watermark_eiluminat.png',
            'lustreled.myshopify.com'    => 'watermark_lustreled.png',
            'powerleds-ro.myshopify.com' => 'watermark_power.png',
        ];

        $filename = $map[strtolower($domain)] ?? null;
        return $filename ? storage_path('app/watermark/'.$filename) : null;
    }

    private function graphqlRequest(Shop $shop, string $query, array $variables = []): ?array
    {
        $version = $shop->api_version ?: '2025-01';
        $endpoint = "https://{$shop->domain}/admin/api/{$version}/graphql.json";

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $shop->access_token,
            'Content-Type' => 'application/json',
        ])->post($endpoint, [
            'query' => $query,
            'variables' => $variables,
        ]);

        if ($response->failed()) {
            Log::error('Shopify GraphQL request failed', [
                'shop' => $shop->domain,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        return $response->json();
    }

    private function fetchProductMediaIds(Shop $shop, string $productGid): array
    {
        $query = <<<'GQL'
        query ProductMedia($id: ID!) {
          product(id: $id) {
            media(first: 250) {
              nodes { id }
            }
          }
        }
        GQL;

        $json = $this->graphqlRequest($shop, $query, ['id' => $productGid]);
        $nodes = $json['data']['product']['media']['nodes'] ?? [];

        return array_map(fn ($node) => $node['id'], $nodes);
    }

    private function stageUploads(Shop $shop, array $processedImages): array
    {
        $input = [];
        foreach ($processedImages as $image) {
            $input[] = [
                'filename' => $image['filename'],
                'mimeType' => $image['mime'] ?? 'image/jpeg',
                'resource' => 'IMAGE',
                'httpMethod' => 'POST',
                'fileSize' => (string) (filesize($image['path']) ?: 0),
            ];
        }

        $mutation = <<<'GQL'
        mutation StageUploads($input: [StagedUploadInput!]!) {
          stagedUploadsCreate(input: $input) {
            stagedTargets {
              url
              resourceUrl
              parameters { name value }
            }
            userErrors { field message }
          }
        }
        GQL;

        $response = $this->graphqlRequest($shop, $mutation, ['input' => $input]);
        $targets = $response['data']['stagedUploadsCreate']['stagedTargets'] ?? [];

        if (isset($response['data']['stagedUploadsCreate']['userErrors']) &&
            !empty($response['data']['stagedUploadsCreate']['userErrors'])) {
            Log::error('Staged upload errors', [
                'shop' => $shop->domain,
                'errors' => $response['data']['stagedUploadsCreate']['userErrors'],
            ]);
        }

        if (count($targets) !== count($input)) {
            Log::error('Staged upload target mismatch', [
                'shop' => $shop->domain,
                'expected' => count($input),
                'received' => count($targets),
                'response' => $response,
            ]);
        }

        return $targets;
    }

    private function uploadToStagedTarget(array $target, string $path, string $filename, string $shopDomain): bool
    {
        $multipart = [];
        foreach ($target['parameters'] ?? [] as $param) {
            $multipart[] = [
                'name' => $param['name'],
                'contents' => $param['value'],
            ];
        }

        $stream = fopen($path, 'r');

        if (empty($target['url'])) {
            Log::error('Staged upload missing URL', ['target' => $target]);
            return false;
        }

        $multipart[] = [
            'name' => 'file',
            'contents' => $stream,
            'filename' => $filename,
        ];

        $response = Http::asMultipart()->post($target['url'], $multipart);
        fclose($stream);

        if ($response->failed()) {
            Log::error('Staged upload HTTP failed', [
                'shop' => $shopDomain,
                'filename' => $filename,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return false;
        }

        return true;
    }

    private function mimeFromExtension(string $ext): string
    {
        $ext = strtolower(ltrim($ext, '.'));
        return match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }

    private function legacyId(int|string $value): string
    {
        $value = (string) $value;
        return str_contains($value, '/')
            ? Str::afterLast($value, '/')
            : $value;
    }

    private function buildWatermarkSummary(Shop $shop, string $productGid): ?array
    {
        $nodes = $this->fetchProductImagesForSummary($shop, $productGid);
        if (empty($nodes)) {
            return null;
        }

        $images = [];
        foreach (array_values($nodes) as $index => $node) {
            $images[] = [
                'position' => $index + 1,
                'url' => $node['src'] ?? null,
                'image_id' => $node['id'] ?? null,
            ];
        }

        return [
            'shop' => $shop->domain,
            'product_id' => $this->productId,
            'count' => count($images),
            'processed_at' => now()->toIso8601String(),
            'images' => $images,
        ];
    }

    private function fetchProductImagesForSummary(Shop $shop, string $productGid): array
    {
        $query = <<<'GQL'
        query WatermarkImages($id: ID!) {
          product(id: $id) {
            images(first: 250) {
              nodes {
                id
                src
              }
            }
          }
        }
        GQL;

        $json = $this->graphqlRequest($shop, $query, ['id' => $productGid]);
        return $json['data']['product']['images']['nodes'] ?? [];
    }

    private function updateWatermarkMetafield(Shop $shop, string $productGid, array $summary): void
    {
        $mutation = <<<'GQL'
        mutation SetWatermarkSummary($metafields: [MetafieldsSetInput!]!) {
          metafieldsSet(metafields: $metafields) {
            metafields { id }
            userErrors { field message }
          }
        }
        GQL;

        $variables = [
            'metafields' => [[
                'ownerId' => $productGid,
                'namespace' => 'custom',
                'key' => 'watermarled',
                'type' => 'json',
                'value' => json_encode($summary),
            ]],
        ];

        $response = $this->graphqlRequest($shop, $mutation, $variables);
        if (!$response) {
            Log::error('Watermark metafield update failed', [
                'shop' => $shop->domain,
                'product_id' => $this->productId,
                'reason' => 'request_failed',
            ]);
            return;
        }

        $errors = $response['data']['metafieldsSet']['userErrors'] ?? [];
        if (!empty($errors)) {
            Log::error('Watermark metafield user errors', [
                'shop' => $shop->domain,
                'product_id' => $this->productId,
                'errors' => $errors,
            ]);
        } else {
            Log::info('Watermark metafield updated', [
                'shop' => $shop->domain,
                'product_id' => $this->productId,
                'count' => $summary['count'],
            ]);
        }
    }

    private function markProcess(string $status, array $attributes = []): void
    {
        if (!$this->processId) {
            return;
        }

        $process = ProductMediaProcess::find($this->processId);
        if (!$process) {
            return;
        }

        $update = array_merge(['status' => $status], $attributes);

        if (!array_key_exists('processed_count', $update)) {
            $update['processed_count'] = $process->processed_count;
        }

        $process->fill($update);
        $process->save();
    }
}
