<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Shopify\BemWatermark\BemProductWatermarkMetafieldService;
use App\Services\Shopify\BemWatermark\BemShopifyStagedUploadService;
use App\Services\Shopify\BemWatermark\BemWatermarkEligibilityService;
use App\Services\Shopify\BemWatermark\BemWatermarkImageProcessor;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BemWatermarkFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanupBemTempFiles();
    }

    protected function tearDown(): void
    {
        $this->cleanupBemTempFiles();

        parent::tearDown();
    }

    private function cleanupBemTempFiles(): void
    {
        $dir = storage_path('app/watermark/bem_tmp/tests');
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    private function bemTestTempFiles(): array
    {
        $dir = storage_path('app/watermark/bem_tmp/tests');
        if (!is_dir($dir)) {
            return [];
        }

        return glob($dir.'/*') ?: [];
    }

    public function test_bem_watermark_components_process_backup_image_replace_target_images_and_set_metafield(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension is required for the BEM watermark e2e test.');
        }

        Config::set('features.bem_watermark_sync.enabled', true);
        Config::set('features.bem_watermark_sync.dry_run', false);
        Config::set('features.bem_watermark_sync.required_tag', 'wm_test');
        Config::set('features.bem_watermark_sync.backup_shop_domain', 'eiluminatbackup.myshopify.com');
        Config::set('features.bem_watermark_sync.target_shop_domains', []);

        $target = $this->shop(4, 'lustreled.myshopify.com');
        $backup = $this->shop(6, 'eiluminatbackup.myshopify.com');

        $metafieldPayload = null;
        $cleanImage = $this->pngFixture();

        $mediaOperations = [];

        Http::fake(function ($request) use ($cleanImage, &$metafieldPayload, &$mediaOperations) {
            $url = $request->url();

            if ($url === 'https://cdn.test/backup-clean-image.png') {
                return Http::response($cleanImage, 200, ['Content-Type' => 'image/png']);
            }

            if ($url === 'https://staged.test/upload/1') {
                return Http::response('', 204);
            }

            $body = $request->data();
            $query = (string) ($body['query'] ?? '');

            if (str_contains($url, 'lustreled.myshopify.com') && str_contains($query, 'BemStageUploads')) {
                return Http::response([
                    'data' => [
                        'stagedUploadsCreate' => [
                            'stagedTargets' => [[
                                'url' => 'https://staged.test/upload/1',
                                'resourceUrl' => 'https://cdn.shopify.test/staged/lustreled_lustra-led-moderna_w_p_1.png',
                                'parameters' => [
                                    ['name' => 'key', 'value' => 'value'],
                                ],
                            ]],
                            'userErrors' => [],
                        ],
                    ],
                ]);
            }

            if (str_contains($url, 'lustreled.myshopify.com') && str_contains($query, 'BemProductImageMediaIds')) {
                return Http::response([
                    'data' => [
                        'product' => [
                            'media' => [
                                'nodes' => [[
                                    'id' => 'gid://shopify/MediaImage/old',
                                    'mediaContentType' => 'IMAGE',
                                ]],
                            ],
                        ],
                    ],
                ]);
            }

            if (str_contains($url, 'lustreled.myshopify.com') && str_contains($query, 'BemAppendProductImages')) {
                $mediaOperations[] = 'append';

                return Http::response([
                    'data' => [
                        'productCreateMedia' => [
                            'media' => [['id' => 'gid://shopify/MediaImage/new', 'status' => 'PROCESSING']],
                            'mediaUserErrors' => [],
                        ],
                    ],
                ]);
            }

            if (str_contains($url, 'lustreled.myshopify.com') && str_contains($query, 'BemProductWatermarkedMediaImages')) {
                return Http::response([
                    'data' => [
                        'product' => [
                            'media' => [
                                'nodes' => [[
                                    'id' => 'gid://shopify/MediaImage/new',
                                    'mediaContentType' => 'IMAGE',
                                    'status' => 'READY',
                                    'preview' => [
                                        'image' => [
                                            'url' => 'https://cdn.shopify.test/final/lustreled_lustra-led-moderna_w_p_1.png',
                                        ],
                                    ],
                                    'image' => [
                                        'url' => 'https://cdn.shopify.test/final/lustreled_lustra-led-moderna_w_p_1.png',
                                    ],
                                ]],
                            ],
                        ],
                    ],
                ]);
            }

            if (str_contains($url, 'lustreled.myshopify.com') && str_contains($query, 'BemDeleteProductImages')) {
                $mediaOperations[] = 'delete';

                return Http::response([
                    'data' => [
                        'productDeleteMedia' => [
                            'deletedMediaIds' => ['gid://shopify/MediaImage/old'],
                            'mediaUserErrors' => [],
                        ],
                    ],
                ]);
            }

            if (str_contains($url, 'lustreled.myshopify.com') && str_contains($query, 'BemSetProductWatermarked')) {
                $metafieldPayload = json_decode($body['variables']['metafields'][0]['value'], true);

                return Http::response([
                    'data' => [
                        'metafieldsSet' => [
                            'metafields' => [['id' => 'gid://shopify/Metafield/1', 'namespace' => 'prod', 'key' => 'watermarked', 'type' => 'json']],
                            'userErrors' => [],
                        ],
                    ],
                ]);
            }

            return Http::response(['errors' => [['message' => 'Unexpected request '.$url]]], 500);
        });

        $backupImages = [[
            'position' => 1,
            'source_url' => 'https://cdn.test/backup-clean-image.png',
            'image_id' => 'gid://shopify/ProductImage/1',
            'alt' => 'Clean image',
            'original_extension' => 'png',
        ]];

        $processedResult = app(BemWatermarkImageProcessor::class)->process(
            $target,
            'Lustra LED Moderna',
            $backupImages
        );

        $processedPath = $processedResult['processed'][0]['path'] ?? null;
        $this->assertIsString($processedPath);
        $this->assertFileExists($processedPath);
        $this->assertSame([320, 320], array_slice(getimagesize($processedPath), 0, 2));
        $this->assertNotSame($cleanImage, file_get_contents($processedPath));

        $uploadedImages = app(BemShopifyStagedUploadService::class)->replaceProductImages(
            $target,
            'gid://shopify/Product/444',
            $processedResult['processed']
        );

        $images = array_map(static function (array $image) use ($uploadedImages): array {
            foreach ($uploadedImages as $uploadedImage) {
                if (($uploadedImage['position'] ?? null) === ($image['position'] ?? null)) {
                    return array_merge($image, $uploadedImage);
                }
            }

            return $image;
        }, $processedResult['processed']);

        app(BemProductWatermarkMetafieldService::class)->update($target, 'gid://shopify/Product/444', [
            'source_shop' => $backup->domain,
            'source_product_id' => 666,
            'source_product_gid' => 'gid://shopify/Product/666',
            'target_shop' => $target->domain,
            'target_product_id' => 444,
            'target_product_gid' => 'gid://shopify/Product/444',
            'updated_at' => now()->toIso8601String(),
            'dry_run' => false,
            'images' => array_map(static fn ($image) => [
                'position' => $image['position'] ?? null,
                'source_url' => $image['source_url'] ?? null,
                'watermarked_url' => $image['watermarked_url'] ?? null,
                'filename' => $image['filename'] ?? null,
                'original_extension' => $image['original_extension'] ?? null,
                'status' => $image['status'] ?? null,
            ], $images),
        ]);

        app(BemWatermarkImageProcessor::class)->cleanup($processedResult['temp_paths']);

        $this->assertTrue(app(BemWatermarkEligibilityService::class)->isEligiblePayloadForTarget([
            'tags' => 'wm_test,noutati',
        ], $target));

        $this->assertIsArray($metafieldPayload);
        $this->assertSame('eiluminatbackup.myshopify.com', $metafieldPayload['source_shop']);
        $this->assertSame(666, $metafieldPayload['source_product_id']);
        $this->assertSame('lustreled.myshopify.com', $metafieldPayload['target_shop']);
        $this->assertFalse($metafieldPayload['dry_run']);
        $this->assertSame('lustreled_lustra-led-moderna_w_p_1.png', $metafieldPayload['images'][0]['filename']);
        $this->assertSame('png', $metafieldPayload['images'][0]['original_extension']);
        $this->assertSame('completed', $metafieldPayload['images'][0]['status']);
        $this->assertSame('https://cdn.shopify.test/final/lustreled_lustra-led-moderna_w_p_1.png', $metafieldPayload['images'][0]['watermarked_url']);
        $this->assertSame(['append', 'delete'], $mediaOperations);
        $this->assertSame([], $this->bemTestTempFiles());
    }

    public function test_bem_safe_replace_keeps_existing_images_when_appended_media_fails(): void
    {
        $target = $this->shop(4, 'lustreled.myshopify.com');
        $processedPath = storage_path('app/watermark/bem_tmp/tests/manual/test-failed-replacement.png');

        if (!is_dir(dirname($processedPath))) {
            mkdir(dirname($processedPath), 0755, true);
        }

        file_put_contents($processedPath, $this->pngFixture());
        $appendCalled = false;
        $deleteCalled = false;

        Http::fake(function ($request) use (&$appendCalled, &$deleteCalled) {
            $url = $request->url();
            if ($url === 'https://staged.test/failed-upload/1') {
                return Http::response('', 204);
            }

            $query = (string) ($request->data()['query'] ?? '');
            if (str_contains($query, 'BemStageUploads')) {
                return Http::response(['data' => ['stagedUploadsCreate' => [
                    'stagedTargets' => [[
                        'url' => 'https://staged.test/failed-upload/1',
                        'resourceUrl' => 'https://cdn.shopify.test/staged/failed.png',
                        'parameters' => [['name' => 'key', 'value' => 'value']],
                    ]],
                    'userErrors' => [],
                ]]]);
            }

            if (str_contains($query, 'BemProductImageMediaIds')) {
                return Http::response(['data' => ['product' => ['media' => ['nodes' => [[
                    'id' => 'gid://shopify/MediaImage/original',
                    'mediaContentType' => 'IMAGE',
                ]]]]]]);
            }

            if (str_contains($query, 'BemAppendProductImages')) {
                $appendCalled = true;
                return Http::response(['data' => ['productCreateMedia' => [
                    'media' => [['id' => 'gid://shopify/MediaImage/failed', 'status' => 'PROCESSING']],
                    'mediaUserErrors' => [],
                ]]]);
            }

            if (str_contains($query, 'BemProductWatermarkedMediaImages')) {
                return Http::response(['data' => ['product' => ['media' => ['nodes' => [[
                    'id' => 'gid://shopify/MediaImage/failed',
                    'mediaContentType' => 'IMAGE',
                    'status' => 'FAILED',
                    'preview' => ['image' => null],
                    'image' => null,
                ]]]]]]);
            }

            if (str_contains($query, 'BemDeleteProductImages')) {
                $deleteCalled = true;
            }

            return Http::response(['errors' => [['message' => 'Unexpected request '.$url]]], 500);
        });

        try {
            app(BemShopifyStagedUploadService::class)->replaceProductImages(
                $target,
                'gid://shopify/Product/failed',
                [[
                    'position' => 1,
                    'source_url' => 'https://cdn.test/original.png',
                    'filename' => 'failed.png',
                    'original_extension' => 'png',
                    'status' => 'processed',
                    'path' => $processedPath,
                    'mime' => 'image/png',
                    'alt' => 'Failed replacement',
                ]]
            );

            $this->fail('Expected failed replacement media to stop the safe swap.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failed', strtolower($e->getMessage()));
        } finally {
            @unlink($processedPath);
        }

        $this->assertTrue($appendCalled);
        $this->assertFalse($deleteCalled);
    }

    public function test_bem_watermark_processor_preserves_webp_extension(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is required for the BEM watermark WebP test.');
        }

        Config::set('features.bem_watermark_sync.width_ratio', 0.25);
        Config::set('features.bem_watermark_sync.opacity', 15);

        $target = $this->shop(4, 'lustreled.myshopify.com');
        $cleanImage = $this->webpFixture();

        Http::fake([
            'https://cdn.test/backup-clean-image.webp' => Http::response($cleanImage, 200, ['Content-Type' => 'image/webp']),
        ]);

        $processedResult = app(BemWatermarkImageProcessor::class)->process($target, 'Lustra WebP', [[
            'position' => 1,
            'source_url' => 'https://cdn.test/backup-clean-image.webp',
            'alt' => 'Clean WebP image',
            'original_extension' => 'webp',
        ]]);

        $processed = $processedResult['processed'][0];
        $processedPath = $processed['path'];

        $this->assertSame('processed', $processed['status']);
        $this->assertSame('webp', $processed['original_extension']);
        $this->assertSame('image/webp', $processed['mime']);
        $this->assertStringEndsWith('.webp', $processed['filename']);
        $this->assertStringEndsWith('.webp', $processedPath);
        $this->assertSame([320, 320], array_slice(getimagesize($processedPath), 0, 2));
        $this->assertNotSame($cleanImage, file_get_contents($processedPath));

        app(BemWatermarkImageProcessor::class)->cleanup($processedResult['temp_paths']);

        $this->assertSame([], $this->bemTestTempFiles());
    }

    public function test_bem_watermark_processor_preserves_png_transparency(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension is required for the BEM watermark transparent PNG test.');
        }

        Config::set('features.bem_watermark_sync.width_ratio', 0.25);
        Config::set('features.bem_watermark_sync.opacity', 15);

        $target = $this->shop(5, 'powerleds-ro.myshopify.com');
        $cleanImage = $this->transparentPngFixture();

        Http::fake([
            'https://cdn.test/backup-transparent-image.png' => Http::response($cleanImage, 200, ['Content-Type' => 'image/png']),
        ]);

        $processedResult = app(BemWatermarkImageProcessor::class)->process($target, 'Lustra PNG Transparent', [[
            'position' => 1,
            'source_url' => 'https://cdn.test/backup-transparent-image.png',
            'alt' => 'Transparent PNG image',
            'original_extension' => 'png',
        ]]);

        $processedPath = $processedResult['processed'][0]['path'];
        $processedImage = imagecreatefrompng($processedPath);

        $this->assertSame('processed', $processedResult['processed'][0]['status']);
        $this->assertSame('png', $processedResult['processed'][0]['original_extension']);
        $this->assertSame(127, $this->pngAlphaAt($processedImage, 10, 10));
        $this->assertSame(127, $this->pngAlphaAt($processedImage, 80, 80));

        imagedestroy($processedImage);
        app(BemWatermarkImageProcessor::class)->cleanup($processedResult['temp_paths']);

        $this->assertSame([], $this->bemTestTempFiles());
    }

    public function test_bem_staged_upload_can_append_then_delete_original_media(): void
    {
        $target = $this->shop(3, 'eiluminat.myshopify.com');
        $processedPath = storage_path('app/watermark/bem_tmp/tests/manual/test-append.png');

        if (!is_dir(dirname($processedPath))) {
            mkdir(dirname($processedPath), 0755, true);
        }

        file_put_contents($processedPath, $this->pngFixture());

        $appendCalled = false;
        $deleteCalled = false;

        Http::fake(function ($request) use (&$appendCalled, &$deleteCalled) {
            $url = $request->url();

            if ($url === 'https://staged.test/source-upload/1') {
                return Http::response('', 204);
            }

            $body = $request->data();
            $query = (string) ($body['query'] ?? '');

            if (str_contains($query, 'BemStageUploads')) {
                return Http::response([
                    'data' => [
                        'stagedUploadsCreate' => [
                            'stagedTargets' => [[
                                'url' => 'https://staged.test/source-upload/1',
                                'resourceUrl' => 'https://cdn.shopify.test/staged/eiluminat-source.png',
                                'parameters' => [['name' => 'key', 'value' => 'value']],
                            ]],
                            'userErrors' => [],
                        ],
                    ],
                ]);
            }

            if (str_contains($query, 'BemAppendProductImages')) {
                $appendCalled = true;

                return Http::response([
                    'data' => [
                        'productCreateMedia' => [
                            'media' => [['id' => 'gid://shopify/MediaImage/new', 'status' => 'READY']],
                            'mediaUserErrors' => [],
                        ],
                    ],
                ]);
            }

            if (str_contains($query, 'BemDeleteProductImages')) {
                $deleteCalled = true;

                return Http::response([
                    'data' => [
                        'productDeleteMedia' => [
                            'deletedMediaIds' => ['gid://shopify/MediaImage/original'],
                            'mediaUserErrors' => [],
                        ],
                    ],
                ]);
            }

            return Http::response(['errors' => [['message' => 'Unexpected request '.$url]]], 500);
        });

        $uploaded = app(BemShopifyStagedUploadService::class)->appendProductImages(
            $target,
            'gid://shopify/Product/333',
            [[
                'position' => 1,
                'source_url' => 'https://cdn.test/original.png',
                'filename' => 'eiluminat-source.png',
                'original_extension' => 'png',
                'status' => 'processed',
                'path' => $processedPath,
                'mime' => 'image/png',
                'alt' => 'Source image',
            ]]
        );

        app(BemShopifyStagedUploadService::class)->deleteProductMedia(
            $target,
            'gid://shopify/Product/333',
            ['gid://shopify/MediaImage/original']
        );

        @unlink($processedPath);

        $this->assertTrue($appendCalled);
        $this->assertTrue($deleteCalled);
        $this->assertSame('uploaded', $uploaded[0]['status']);
        $this->assertSame('https://cdn.shopify.test/staged/eiluminat-source.png', $uploaded[0]['watermarked_url']);
    }

    public function test_partial_media_create_is_cleaned_up_before_retry(): void
    {
        $target = $this->shop(4, 'lustreled.myshopify.com');
        $operations = [];

        Http::fake(function ($request) use (&$operations) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'BemAppendProductImages')) {
                $operations[] = 'append-partial';

                return Http::response(['data' => ['productCreateMedia' => [
                    'media' => [[
                        'id' => 'gid://shopify/MediaImage/partial',
                        'status' => 'PROCESSING',
                    ]],
                    'mediaUserErrors' => [[
                        'field' => ['media', '1'],
                        'message' => 'Invalid image',
                    ]],
                ]]]);
            }

            if (str_contains($query, 'BemDeleteProductImages')) {
                $operations[] = 'cleanup-partial';
                $this->assertSame(
                    ['gid://shopify/MediaImage/partial'],
                    $request->data()['variables']['mediaIds']
                );

                return Http::response(['data' => ['productDeleteMedia' => [
                    'deletedMediaIds' => ['gid://shopify/MediaImage/partial'],
                    'mediaUserErrors' => [],
                ]]]);
            }

            return Http::response(['errors' => [['message' => 'Unexpected request']]], 500);
        });

        $service = app(BemShopifyStagedUploadService::class);
        $method = new \ReflectionMethod($service, 'createMedia');

        try {
            $method->invoke($service, $target, 'gid://shopify/Product/333', [[
                'mediaContentType' => 'IMAGE',
                'originalSource' => 'https://cdn.shopify.test/staged/one.jpg',
            ], [
                'mediaContentType' => 'IMAGE',
                'originalSource' => 'invalid',
            ]]);
            $this->fail('Expected partial media create to throw');
        } catch (\ReflectionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->assertStringContainsString('userErrors', $e->getMessage());
        }

        $this->assertSame(['append-partial', 'cleanup-partial'], $operations);
    }

    private function shop(int $id, string $domain): Shop
    {
        $shop = new Shop();
        $shop->id = $id;
        $shop->name = $domain;
        $shop->domain = $domain;
        $shop->access_token = 'test-token';
        $shop->api_version = '2025-01';
        $shop->is_active = true;

        return $shop;
    }

    private function pngFixture(): string
    {
        $image = imagecreatetruecolor(320, 320);
        $background = imagecolorallocate($image, 240, 240, 240);
        imagefill($image, 0, 0, $background);

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function webpFixture(): string
    {
        $image = imagecreatetruecolor(320, 320);
        $background = imagecolorallocate($image, 180, 180, 180);
        imagefill($image, 0, 0, $background);

        ob_start();
        imagewebp($image, null, 100);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function transparentPngFixture(): string
    {
        $image = imagecreatetruecolor(320, 320);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));

        imagefilledrectangle($image, 130, 130, 190, 190, imagecolorallocatealpha($image, 210, 190, 160, 0));

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function pngAlphaAt(\GdImage $image, int $x, int $y): int
    {
        return (imagecolorat($image, $x, $y) & 0x7F000000) >> 24;
    }
}
