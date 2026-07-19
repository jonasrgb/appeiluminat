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

        $existingMediaIds = $this->fetchProductImageMediaIds($target, $productGid);
        $createdMedia = $this->createMedia($target, $productGid, $mediaInputs);
        $createdMediaIds = $this->createdMediaIds($createdMedia, count($uploadable));
        $shopifyImages = $this->waitForReadyProductMedia($target, $productGid, $createdMediaIds);
        $this->deleteProductMedia($target, $productGid, $existingMediaIds);

        foreach ($uploadable as $index => $image) {
            $shopifyImage = $shopifyImages[$index] ?? null;
            $uploadable[$index]['media_id'] = $createdMediaIds[$index];
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

            $existingMediaIds = $this->fetchProductImageMediaIds($target, $productGid);
            $createdMedia = $this->createMedia($target, $productGid, $mediaInputs);
            $createdMediaIds = $this->createdMediaIds($createdMedia, count($images));
            $shopifyImages = $this->waitForReadyProductMedia($target, $productGid, $createdMediaIds);
            $this->deleteProductMedia($target, $productGid, $existingMediaIds);

            foreach ($images as $index => $image) {
                $shopifyImage = $shopifyImages[$index] ?? null;
                $images[$index]['media_id'] = $createdMediaIds[$index];
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
                    $this->removeEmptyTempDirectoryForPath($path);
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

        $createdMedia = $this->createMedia($target, $productGid, $mediaInputs);
        $createdMediaIds = $this->createdMediaIds($createdMedia, count($uploadable));

        foreach ($uploadable as $index => $image) {
            $uploadable[$index]['media_id'] = $createdMediaIds[$index];
        }

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

            if (empty($image['path']) || !is_file($image['path'])) {
                throw new \RuntimeException('BEM staged upload temp file missing for '.($image['filename'] ?? 'unknown'));
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
        $tmpDir = $this->createTempDirectory();

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

    private function createTempDirectory(): string
    {
        $baseDir = storage_path('app/watermark/bem_tmp/'.(app()->runningUnitTests() ? 'tests' : 'jobs'));
        $tmpDir = $baseDir.'/'.Str::uuid();

        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        return $tmpDir;
    }

    private function removeEmptyTempDirectoryForPath(string $path): void
    {
        $baseDir = realpath(storage_path('app/watermark/bem_tmp/'.(app()->runningUnitTests() ? 'tests' : 'jobs')));
        $dir = dirname($path);
        $realDir = is_dir($dir) ? realpath($dir) : false;

        if (!$baseDir || !$realDir || !str_starts_with($realDir, $baseDir.DIRECTORY_SEPARATOR)) {
            return;
        }

        @rmdir($realDir);
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

    /** @return array<int, array{id: string|null, status: string|null}> */
    private function createMedia(Shop $target, string $productGid, array $mediaInputs): array
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

        $createdMedia = array_values($response['data']['productCreateMedia']['media'] ?? []);
        $errors = $response['data']['productCreateMedia']['mediaUserErrors'] ?? [];
        if (!empty($errors)) {
            $partialMediaIds = array_values(array_filter(array_map(
                static fn (array $media): ?string => $media['id'] ?? null,
                $createdMedia
            )));

            if ($partialMediaIds) {
                try {
                    $this->deleteProductMedia($target, $productGid, $partialMediaIds);
                } catch (\Throwable $cleanupError) {
                    throw new \RuntimeException(
                        'BEM append product images userErrors and partial media cleanup failed: '
                        .json_encode($errors).'; cleanup: '.$cleanupError->getMessage(),
                        previous: $cleanupError
                    );
                }
            }

            throw new \RuntimeException('BEM append product images userErrors: '.json_encode($errors));
        }

        return $createdMedia;
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
     * @param array<int, string> $mediaIds
     * @return array<int, array{url: string|null, id: string|null, status: string|null}>
     */
    public function waitForReadyProductMedia(Shop $target, string $productGid, array $mediaIds): array
    {
        $mediaIds = array_values(array_unique(array_filter(array_map('strval', $mediaIds))));
        if (empty($mediaIds)) {
            throw new \RuntimeException('BEM appended media IDs are missing');
        }

        $imagesById = [];
        $maxAttempts = 30;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $imagesById = [];
            foreach ($this->fetchProductMediaImages($target, $productGid) as $image) {
                if (!empty($image['id'])) {
                    $imagesById[(string) $image['id']] = $image;
                }
            }

            $ready = true;
            foreach ($mediaIds as $mediaId) {
                $image = $imagesById[$mediaId] ?? null;
                if (($image['status'] ?? null) === 'FAILED') {
                    throw new \RuntimeException('BEM appended media failed for '.$mediaId);
                }

                if (($image['status'] ?? null) !== 'READY' || empty($image['url'])) {
                    $ready = false;
                }
            }

            if ($ready) {
                return array_map(
                    static fn (string $mediaId): array => $imagesById[$mediaId],
                    $mediaIds
                );
            }

            usleep(500000);
        }

        Log::warning('BEM appended product images were not ready; existing media kept', [
            'target_shop' => $target->domain,
            'product_gid' => $productGid,
            'expected_media_ids' => $mediaIds,
            'statuses' => array_values(array_map(
                static fn (string $mediaId) => $imagesById[$mediaId]['status'] ?? null,
                $mediaIds
            )),
        ]);

        throw new \RuntimeException('BEM appended media readiness timeout; existing media kept');
    }

    /**
     * @param array<int, array{id?: string|null}> $createdMedia
     * @return array<int, string>
     */
    private function createdMediaIds(array $createdMedia, int $expectedCount): array
    {
        $mediaIds = array_values(array_filter(array_map(
            static fn (array $media): ?string => !empty($media['id']) ? (string) $media['id'] : null,
            $createdMedia
        )));

        if (count($mediaIds) !== $expectedCount || count(array_unique($mediaIds)) !== $expectedCount) {
            throw new \RuntimeException('BEM appended media ID count mismatch');
        }

        return $mediaIds;
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
