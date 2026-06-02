<?php

namespace App\Services\Shopify\BemWatermark;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BemShopifyStagedUploadService
{
    private const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;

    public function __construct(private readonly BemShopifyGraphqlClient $graphql)
    {
    }

    /**
     * Uploads already processed watermark images to Shopify staged storage and
     * returns media-ready records. This does not require a product id and does
     * not delete or mutate existing product media.
     *
     * @param array<int, array<string, mixed>> $processedImages
     * @return array<int, array<string, mixed>>
     */
    public function uploadProcessedImagesForProductCreate(Shop $target, array $processedImages): array
    {
        $uploadable = array_values(array_filter(
            $processedImages,
            static fn ($image) => ($image['status'] ?? null) === 'processed' && !empty($image['path'])
        ));

        if (empty($uploadable)) {
            throw new \RuntimeException('No processed BEM watermark images to upload for product create');
        }

        $targets = $this->stageUploads($target, $uploadable);

        foreach ($uploadable as $index => $image) {
            $targetUpload = $targets[$index] ?? null;
            if (!$targetUpload) {
                throw new \RuntimeException('Missing staged upload target for '.$image['filename']);
            }

            $this->uploadToStagedTarget($targetUpload, $image);

            $resourceUrl = $targetUpload['resourceUrl'] ?? null;
            if (!$resourceUrl) {
                throw new \RuntimeException('Missing resourceUrl for '.$image['filename']);
            }

            $uploadable[$index]['watermarked_url'] = $resourceUrl;
            $uploadable[$index]['status'] = 'uploaded';
        }

        return $uploadable;
    }

    /**
     * @param array<int, array<string, mixed>> $processedImages
     * @return array<int, array<string, mixed>>
     */
    public function replaceProductImages(Shop $target, string $productGid, array $processedImages): array
    {
        $uploadable = array_values(array_filter(
            $processedImages,
            static fn ($image) => ($image['status'] ?? null) === 'processed' && !empty($image['path'])
        ));

        if (empty($uploadable)) {
            throw new \RuntimeException('No processed BEM watermark images to upload');
        }

        $targets = $this->stageUploads($target, $uploadable);
        $mediaInputs = [];

        foreach ($uploadable as $index => $image) {
            $targetUpload = $targets[$index] ?? null;
            if (!$targetUpload) {
                throw new \RuntimeException('Missing staged upload target for '.$image['filename']);
            }

            $this->uploadToStagedTarget($targetUpload, $image);

            $resourceUrl = $targetUpload['resourceUrl'] ?? null;
            if (!$resourceUrl) {
                throw new \RuntimeException('Missing resourceUrl for '.$image['filename']);
            }

            $uploadable[$index]['watermarked_url'] = $resourceUrl;
            $uploadable[$index]['status'] = 'uploaded';

            $mediaInputs[] = [
                'alt' => $image['alt'] ?? null,
                'mediaContentType' => 'IMAGE',
                'originalSource' => $resourceUrl,
            ];
        }

        $mediaIds = $this->fetchProductMediaIds($target, $productGid);
        $this->replaceMedia($target, $productGid, $mediaIds, $mediaInputs);

        $shopifyImages = $this->waitForProductImages($target, $productGid, count($uploadable));
        foreach ($uploadable as $index => $image) {
            $shopifyImage = $shopifyImages[$index] ?? null;
            if (!empty($shopifyImage['url'])) {
                $uploadable[$index]['watermarked_url'] = $shopifyImage['url'];
            }
            $uploadable[$index]['status'] = 'completed';
        }

        return $uploadable;
    }

    /**
     * Replaces product media using existing remote image URLs, without applying a watermark.
     *
     * @param array<int, array<string, mixed>> $images
     * @return array<int, array<string, mixed>>
     */
    public function replaceProductImagesFromUrls(Shop $target, string $productGid, array $images): array
    {
        $images = array_values(array_filter(
            $images,
            static fn ($image) => !empty($image['source_url'])
        ));

        if (empty($images)) {
            throw new \RuntimeException('No BEM source images to upload from URLs');
        }

        $tempPaths = [];

        try {
            $uploadable = $this->downloadUrlImagesForStagedUpload($images);
            $tempPaths = array_values(array_filter(array_map(
                static fn ($image) => $image['path'] ?? null,
                $uploadable
            )));

            $targets = $this->stageUploads($target, $uploadable);
            $mediaInputs = [];

            foreach ($uploadable as $index => $image) {
                $targetUpload = $targets[$index] ?? null;
                if (!$targetUpload) {
                    throw new \RuntimeException('Missing staged upload target for '.$image['filename']);
                }

                $this->uploadToStagedTarget($targetUpload, $image);

                $resourceUrl = $targetUpload['resourceUrl'] ?? null;
                if (!$resourceUrl) {
                    throw new \RuntimeException('Missing resourceUrl for '.$image['filename']);
                }

                $images[$index]['uploaded_url'] = $resourceUrl;
                $images[$index]['status'] = 'uploaded';

                $mediaInputs[] = [
                    'alt' => $image['alt'] ?? null,
                    'mediaContentType' => 'IMAGE',
                    'originalSource' => $resourceUrl,
                ];
            }

            $mediaIds = $this->fetchProductMediaIds($target, $productGid);
            $this->replaceMedia($target, $productGid, $mediaIds, $mediaInputs);

            $shopifyImages = $this->waitForProductImages($target, $productGid, count($images));
            foreach ($images as $index => $image) {
                $shopifyImage = $shopifyImages[$index] ?? null;
                if (!empty($shopifyImage['url'])) {
                    $images[$index]['uploaded_url'] = $shopifyImage['url'];
                }
                $images[$index]['status'] = 'completed';
            }

            return $images;
        } finally {
            foreach ($tempPaths as $path) {
                if (is_string($path) && $path !== '' && is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * Uploads and appends processed images to a product without deleting any
     * existing media. Callers can delete the old media after they verify that
     * the appended media exists.
     *
     * @param array<int, array<string, mixed>> $processedImages
     * @return array<int, array<string, mixed>>
     */
    public function appendProductImages(Shop $target, string $productGid, array $processedImages): array
    {
        $uploadable = array_values(array_filter(
            $processedImages,
            static fn ($image) => ($image['status'] ?? null) === 'processed' && !empty($image['path'])
        ));

        if (empty($uploadable)) {
            throw new \RuntimeException('No processed BEM watermark images to append');
        }

        $targets = $this->stageUploads($target, $uploadable);
        $mediaInputs = [];

        foreach ($uploadable as $index => $image) {
            $targetUpload = $targets[$index] ?? null;
            if (!$targetUpload) {
                throw new \RuntimeException('Missing staged upload target for '.$image['filename']);
            }

            $this->uploadToStagedTarget($targetUpload, $image);

            $resourceUrl = $targetUpload['resourceUrl'] ?? null;
            if (!$resourceUrl) {
                throw new \RuntimeException('Missing resourceUrl for '.$image['filename']);
            }

            $uploadable[$index]['watermarked_url'] = $resourceUrl;
            $uploadable[$index]['status'] = 'uploaded';

            $mediaInputs[] = [
                'alt' => $image['alt'] ?? null,
                'mediaContentType' => 'IMAGE',
                'originalSource' => $resourceUrl,
            ];
        }

        $this->createMedia($target, $productGid, $mediaInputs);

        return $uploadable;
    }

    /**
     * @param array<int, array<string, mixed>> $images
     * @return array<int, array<string, mixed>>
     */
    private function stageUploads(Shop $target, array $images): array
    {
        $input = [];
        foreach ($images as $image) {
            $input[] = [
                'filename' => $image['filename'],
                'mimeType' => $image['mime'],
                'resource' => 'IMAGE',
                'httpMethod' => 'POST',
                'fileSize' => (string) (filesize($image['path']) ?: 0),
            ];
        }

        $mutation = <<<'GQL'
        mutation BemStageUploads($input: [StagedUploadInput!]!) {
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

        $response = $this->graphql->request($target, $mutation, ['input' => $input]);
        $errors = $response['data']['stagedUploadsCreate']['userErrors'] ?? [];
        if (!empty($errors)) {
            throw new \RuntimeException('BEM stagedUploadsCreate userErrors: '.json_encode($errors));
        }

        $targets = $response['data']['stagedUploadsCreate']['stagedTargets'] ?? [];
        if (count($targets) !== count($input)) {
            throw new \RuntimeException('BEM staged upload target count mismatch');
        }

        return $targets;
    }

    private function uploadToStagedTarget(array $targetUpload, array $image): void
    {
        if (empty($targetUpload['url'])) {
            throw new \RuntimeException('BEM staged upload URL missing');
        }

        $multipart = [];
        foreach ($targetUpload['parameters'] ?? [] as $param) {
            $multipart[] = [
                'name' => $param['name'],
                'contents' => $param['value'],
            ];
        }

        $stream = fopen($image['path'], 'r');
        try {
            $multipart[] = [
                'name' => 'file',
                'contents' => $stream,
                'filename' => $image['filename'],
            ];

            $response = Http::asMultipart()->post($targetUpload['url'], $multipart);
            if ($response->failed()) {
                Log::error('BEM staged upload HTTP failed', [
                    'filename' => $image['filename'],
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw new \RuntimeException('BEM staged upload HTTP failed for '.$image['filename']);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $images
     * @return array<int, array<string, mixed>>
     */
    private function downloadUrlImagesForStagedUpload(array $images): array
    {
        $tmpDir = storage_path('app/watermark/bem_tmp');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $uploadable = [];
        foreach ($images as $index => $image) {
            $sourceUrl = (string) ($image['source_url'] ?? '');
            if ($sourceUrl === '') {
                throw new \RuntimeException('BEM source image URL missing at index '.$index);
            }

            $extension = $this->normalizeExtension((string) ($image['original_extension'] ?? $this->extensionFromUrl($sourceUrl)));
            $filename = $this->filenameFromUrl($sourceUrl) ?: ('bem_original_'.($index + 1).'.'.$extension);
            if (!str_ends_with(strtolower($filename), '.'.$extension)) {
                $filename .= '.'.$extension;
            }

            $response = Http::timeout(60)->get($sourceUrl);
            if ($response->failed()) {
                throw new \RuntimeException('BEM source image download failed at index '.$index.' with status '.$response->status());
            }

            $path = $tmpDir.'/bem_backup_original_'.Str::uuid().'.'.$extension;
            file_put_contents($path, $response->body());

            if ((filesize($path) ?: 0) > self::MAX_UPLOAD_BYTES) {
                @unlink($path);
                throw new \RuntimeException('BEM source image too large for staged upload at index '.$index);
            }

            $uploadable[] = [
                'position' => $image['position'] ?? ($index + 1),
                'source_url' => $sourceUrl,
                'filename' => $filename,
                'path' => $path,
                'mime' => $this->mimeFromExtension($extension),
                'alt' => $image['alt'] ?? null,
                'original_extension' => $extension,
                'status' => 'downloaded',
            ];
        }

        return $uploadable;
    }

    /**
     * @return array<int, string>
     */
    public function fetchProductMediaIds(Shop $target, string $productGid): array
    {
        $query = <<<'GQL'
        query BemProductMediaIds($id: ID!) {
          product(id: $id) {
            media(first: 250) {
              nodes { id }
            }
          }
        }
        GQL;

        $response = $this->graphql->request($target, $query, ['id' => $productGid]);
        $nodes = $response['data']['product']['media']['nodes'] ?? [];

        return array_values(array_filter(array_map(static fn ($node) => $node['id'] ?? null, $nodes)));
    }

    /**
     * @return array<int, string>
     */
    public function fetchProductImageMediaIds(Shop $target, string $productGid): array
    {
        $query = <<<'GQL'
        query BemProductImageMediaIds($id: ID!) {
          product(id: $id) {
            media(first: 250) {
              nodes {
                id
                mediaContentType
              }
            }
          }
        }
        GQL;

        $response = $this->graphql->request($target, $query, ['id' => $productGid]);
        $nodes = $response['data']['product']['media']['nodes'] ?? [];

        return array_values(array_filter(array_map(
            static fn ($node) => ($node['mediaContentType'] ?? null) === 'IMAGE' ? ($node['id'] ?? null) : null,
            $nodes
        )));
    }

    public function deleteProductMedia(Shop $target, string $productGid, array $mediaIds): void
    {
        $mediaIds = array_values(array_filter($mediaIds));
        if (empty($mediaIds)) {
            return;
        }

        $mutation = <<<'GQL'
        mutation BemDeleteProductImages($productId: ID!, $mediaIds: [ID!]!) {
          productDeleteMedia(productId: $productId, mediaIds: $mediaIds) {
            deletedMediaIds
            mediaUserErrors { field message }
          }
        }
        GQL;

        $response = $this->graphql->request($target, $mutation, [
            'productId' => $productGid,
            'mediaIds' => $mediaIds,
        ]);

        $errors = $response['data']['productDeleteMedia']['mediaUserErrors'] ?? [];
        if (!empty($errors)) {
            throw new \RuntimeException('BEM delete product images userErrors: '.json_encode($errors));
        }
    }

    private function createMedia(Shop $target, string $productGid, array $mediaInputs): void
    {
        $mutation = <<<'GQL'
        mutation BemAppendProductImages($productId: ID!, $media: [CreateMediaInput!]!) {
          productCreateMedia(productId: $productId, media: $media) {
            media { id status }
            mediaUserErrors { field message }
          }
        }
        GQL;

        $response = $this->graphql->request($target, $mutation, [
            'productId' => $productGid,
            'media' => $mediaInputs,
        ]);

        $errors = $response['data']['productCreateMedia']['mediaUserErrors'] ?? [];
        if (!empty($errors)) {
            throw new \RuntimeException('BEM append product images userErrors: '.json_encode($errors));
        }
    }

    private function replaceMedia(Shop $target, string $productGid, array $mediaIds, array $mediaInputs): void
    {
        $mutation = <<<'GQL'
        mutation BemReplaceProductImages($productId: ID!, $mediaIds: [ID!]!, $media: [CreateMediaInput!]!) {
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

        $response = $this->graphql->request($target, $mutation, [
            'productId' => $productGid,
            'mediaIds' => $mediaIds,
            'media' => $mediaInputs,
        ]);

        $deleteErrors = $response['data']['deleteResult']['mediaUserErrors'] ?? [];
        $createErrors = $response['data']['createResult']['mediaUserErrors'] ?? [];
        if (!empty($deleteErrors) || !empty($createErrors)) {
            throw new \RuntimeException('BEM replace product images userErrors: '.json_encode([
                'delete' => $deleteErrors,
                'create' => $createErrors,
            ]));
        }
    }

    /**
     * @return array<int, array{url: string|null, id: string|null}>
     */
    public function fetchProductImages(Shop $target, string $productGid): array
    {
        $query = <<<'GQL'
        query BemProductWatermarkedImages($id: ID!) {
          product(id: $id) {
            images(first: 250) {
              nodes {
                id
                url
              }
            }
          }
        }
        GQL;

        $response = $this->graphql->request($target, $query, ['id' => $productGid]);
        $nodes = $response['data']['product']['images']['nodes'] ?? [];

        return array_map(static fn ($node) => [
            'id' => $node['id'] ?? null,
            'url' => $node['url'] ?? null,
        ], $nodes);
    }

    /**
     * @return array<int, array{url: string|null, id: string|null, status: string|null}>
     */
    private function fetchProductMediaImages(Shop $target, string $productGid): array
    {
        $query = <<<'GQL'
        query BemProductWatermarkedMediaImages($id: ID!) {
          product(id: $id) {
            media(first: 250) {
              nodes {
                id
                mediaContentType
                status
                preview {
                  image { url }
                }
                ... on MediaImage {
                  image { url }
                }
              }
            }
          }
        }
        GQL;

        $response = $this->graphql->request($target, $query, ['id' => $productGid]);
        $nodes = $response['data']['product']['media']['nodes'] ?? [];

        return array_values(array_filter(array_map(static function ($node) {
            if (($node['mediaContentType'] ?? null) !== 'IMAGE') {
                return null;
            }

            return [
                'id' => $node['id'] ?? null,
                'url' => $node['image']['url'] ?? ($node['preview']['image']['url'] ?? null),
                'status' => $node['status'] ?? null,
            ];
        }, $nodes)));
    }

    /**
     * @return array<int, array{url: string|null, id: string|null}>
     */
    private function waitForProductImages(Shop $target, string $productGid, int $expectedCount): array
    {
        $images = [];

        $maxAttempts = 30;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $images = $this->fetchProductMediaImages($target, $productGid);
            $ready = count($images) >= $expectedCount;

            foreach (array_slice($images, 0, $expectedCount) as $image) {
                if (($image['status'] ?? null) === 'FAILED' || empty($image['url'])) {
                    $ready = false;
                    break;
                }
            }

            if ($ready) {
                return $images;
            }

            usleep(500000);
        }

        Log::warning('BEM product images were not ready after media replace wait', [
            'target_shop' => $target->domain,
            'product_gid' => $productGid,
            'expected_count' => $expectedCount,
            'actual_count' => count($images),
            'ready_urls' => count(array_filter(
                array_slice($images, 0, $expectedCount),
                static fn ($image) => !empty($image['url'])
            )),
            'statuses' => array_values(array_map(
                static fn ($image) => $image['status'] ?? null,
                array_slice($images, 0, $expectedCount)
            )),
        ]);

        return $images;
    }

    private function filenameFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $filename = basename($path);

        return $filename !== '' ? $filename : null;
    }

    private function extensionFromUrl(string $url): string
    {
        $filename = $this->filenameFromUrl($url);
        $extension = $filename ? pathinfo($filename, PATHINFO_EXTENSION) : '';

        return $this->normalizeExtension($extension ?: 'jpg');
    }

    private function normalizeExtension(string $extension): string
    {
        $extension = strtolower(ltrim($extension, '.'));

        return match ($extension) {
            'jpeg', 'jpg' => 'jpg',
            'png' => 'png',
            'webp' => 'webp',
            default => 'jpg',
        };
    }

    private function mimeFromExtension(string $extension): string
    {
        return match ($this->normalizeExtension($extension)) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /**
     * @param array<int, array<string, mixed>> $images
     * @return array<int, array<string, mixed>>
     */
    public function applyFinalProductImageUrls(Shop $target, string $productGid, array $images): array
    {
        $shopifyImages = $this->fetchProductImages($target, $productGid);

        foreach ($images as $index => $image) {
            $shopifyImage = $shopifyImages[$index] ?? null;
            if (!empty($shopifyImage['url'])) {
                $images[$index]['watermarked_url'] = $shopifyImage['url'];
            }
            if (($images[$index]['status'] ?? null) === 'uploaded') {
                $images[$index]['status'] = 'completed';
            }
        }

        return $images;
    }
}
