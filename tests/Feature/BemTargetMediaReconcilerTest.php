<?php

namespace Tests\Feature;

use App\Models\ProductMirror;
use App\Models\Shop;
use App\Services\Shopify\BemWatermark\BemImageIdentityService;
use App\Services\Shopify\BemWatermark\BemProductWatermarkMetafieldService;
use App\Services\Shopify\BemWatermark\BemShopifyGraphqlClient;
use App\Services\Shopify\BemWatermark\BemShopifyStagedUploadService;
use App\Services\Shopify\BemWatermark\BemTargetMediaReconciler;
use App\Services\Shopify\BemWatermark\BemWatermarkImageProcessor;
use Mockery;
use Tests\TestCase;

class BemTargetMediaReconcilerTest extends TestCase
{
    public function test_zero_live_images_and_missing_manifest_are_unhealthy(): void
    {
        $health = $this->reconciler()->assessHealth(
            liveImages: [],
            manifest: [],
            backupImages: $this->backupImages()
        );

        $this->assertFalse($health['healthy']);
        $this->assertContains('missing_live_images', $health['reasons']);
        $this->assertContains('missing_watermarked_manifest', $health['reasons']);
        $this->assertSame(2, $health['expected_images']);
        $this->assertSame(0, $health['actual_images']);
        $this->assertSame(0, $health['manifest_images']);
    }

    public function test_live_and_manifest_count_mismatches_are_unhealthy(): void
    {
        $health = $this->reconciler()->assessHealth(
            liveImages: [$this->liveImage(1)],
            manifest: ['images' => [$this->manifestImage(1)]],
            backupImages: $this->backupImages()
        );

        $this->assertFalse($health['healthy']);
        $this->assertContains('image_count_mismatch', $health['reasons']);
        $this->assertContains('manifest_count_mismatch', $health['reasons']);
    }

    public function test_mismatched_live_and_manifest_watermarked_urls_are_unhealthy(): void
    {
        $manifest = $this->matchingManifest();
        $manifest['images'][1]['watermarked_url'] = 'https://cdn.target.test/different.jpg?v=99';

        $health = $this->reconciler()->assessHealth(
            liveImages: $this->liveImages(),
            manifest: $manifest,
            backupImages: $this->backupImages()
        );

        $this->assertFalse($health['healthy']);
        $this->assertContains('watermarked_url_mismatch', $health['reasons']);
    }

    public function test_mismatched_manifest_and_backup_source_urls_are_unhealthy(): void
    {
        $manifest = $this->matchingManifest();
        $manifest['images'][0]['source_url'] = 'https://cdn.backup.test/old-original.jpg?v=1';

        $health = $this->reconciler()->assessHealth(
            liveImages: $this->liveImages(),
            manifest: $manifest,
            backupImages: $this->backupImages()
        );

        $this->assertFalse($health['healthy']);
        $this->assertContains('backup_source_url_mismatch', $health['reasons']);
    }

    public function test_positionally_matching_target_is_healthy(): void
    {
        $health = $this->reconciler()->assessHealth(
            liveImages: $this->liveImages(),
            manifest: $this->matchingManifest(),
            backupImages: $this->backupImages()
        );

        $this->assertTrue($health['healthy']);
        $this->assertSame([], $health['reasons']);
        $this->assertSame(2, $health['expected_images']);
        $this->assertSame(2, $health['actual_images']);
        $this->assertSame(2, $health['manifest_images']);
    }

    public function test_unhealthy_target_is_repaired_and_snapshot_is_saved_after_success(): void
    {
        $target = $this->shop(5, 'powerleds-ro.myshopify.com');
        $backup = $this->shop(6, 'eiluminatbackup.myshopify.com');
        $mirror = $this->mirror();
        $processed = [
            $this->processedImage(1),
            $this->processedImage(2),
        ];
        $uploaded = [
            $this->uploadedImage(1),
            $this->uploadedImage(2),
        ];
        $writtenPayload = null;

        $graphql = Mockery::mock(BemShopifyGraphqlClient::class);
        $graphql->shouldReceive('request')->once()->withArgs(function (Shop $shop, string $query, array $variables) use ($target) {
            return $shop === $target
                && str_contains($query, 'BemTargetMediaState')
                && $variables === ['id' => 'gid://shopify/Product/900'];
        })->andReturn([
            'data' => [
                'product' => [
                    'id' => 'gid://shopify/Product/900',
                    'legacyResourceId' => 900,
                    'images' => ['nodes' => []],
                    'metafield' => null,
                ],
            ],
        ]);

        $imageProcessor = Mockery::mock(BemWatermarkImageProcessor::class);
        $imageProcessor->shouldReceive('process')
            ->once()
            ->with($target, 'Test product', $this->backupImages())
            ->andReturn(['processed' => $processed, 'temp_paths' => ['/tmp/bem-target-1.jpg', '/tmp/bem-target-2.jpg']]);
        $imageProcessor->shouldReceive('cleanup')
            ->once()
            ->with(['/tmp/bem-target-1.jpg', '/tmp/bem-target-2.jpg']);

        $uploadService = Mockery::mock(BemShopifyStagedUploadService::class);
        $uploadService->shouldReceive('replaceProductImages')
            ->once()
            ->with($target, 'gid://shopify/Product/900', $processed)
            ->andReturn($uploaded);

        $metafieldService = Mockery::mock(BemProductWatermarkMetafieldService::class);
        $metafieldService->shouldReceive('update')
            ->once()
            ->withArgs(function (Shop $shop, string $gid, array $payload) use ($target, &$writtenPayload) {
                $writtenPayload = $payload;

                return $shop === $target
                    && $gid === 'gid://shopify/Product/900'
                    && ($payload['mode'] ?? null) === 'target_product_update'
                    && count($payload['images'] ?? []) === 2;
            });

        $reconciler = new BemTargetMediaReconciler(
            $graphql,
            $uploadService,
            $imageProcessor,
            $metafieldService,
            app(BemImageIdentityService::class)
        );

        $result = $reconciler->reconcile(
            mirror: $mirror,
            target: $target,
            backupShop: $backup,
            backupProductId: 800,
            backupProductGid: 'gid://shopify/Product/800',
            title: 'Test product',
            backupImages: $this->backupImages()
        );

        $this->assertSame('repaired', $result['status']);
        $this->assertTrue($result['repaired']);
        $this->assertTrue($mirror->wasSaved);
        $this->assertCount(2, $mirror->last_snapshot['images']);
        $this->assertSame('https://cdn.target.test/watermarked-1.jpg', $mirror->last_snapshot['images'][0]['src_canon']);
        $this->assertSame('eiluminatbackup.myshopify.com', $writtenPayload['source_shop']);
        $this->assertSame(800, $writtenPayload['source_product_id']);
        $this->assertSame(900, $writtenPayload['target_product_id']);
    }

    public function test_healthy_target_receives_no_media_or_metafield_writes(): void
    {
        $target = $this->shop(5, 'powerleds-ro.myshopify.com');
        $backup = $this->shop(6, 'eiluminatbackup.myshopify.com');
        $mirror = $this->mirror();

        $graphql = Mockery::mock(BemShopifyGraphqlClient::class);
        $graphql->shouldReceive('request')->once()->andReturn([
            'data' => [
                'product' => [
                    'id' => 'gid://shopify/Product/900',
                    'legacyResourceId' => 900,
                    'images' => ['nodes' => $this->liveImages()],
                    'metafield' => ['value' => json_encode($this->matchingManifest(), JSON_UNESCAPED_SLASHES)],
                ],
            ],
        ]);

        $uploadService = Mockery::mock(BemShopifyStagedUploadService::class);
        $uploadService->shouldNotReceive('replaceProductImages');
        $imageProcessor = Mockery::mock(BemWatermarkImageProcessor::class);
        $imageProcessor->shouldNotReceive('process');
        $imageProcessor->shouldNotReceive('cleanup');
        $metafieldService = Mockery::mock(BemProductWatermarkMetafieldService::class);
        $metafieldService->shouldNotReceive('update');

        $result = (new BemTargetMediaReconciler(
            $graphql,
            $uploadService,
            $imageProcessor,
            $metafieldService,
            app(BemImageIdentityService::class)
        ))->reconcile(
            mirror: $mirror,
            target: $target,
            backupShop: $backup,
            backupProductId: 800,
            backupProductGid: 'gid://shopify/Product/800',
            title: 'Test product',
            backupImages: $this->backupImages()
        );

        $this->assertSame('healthy', $result['status']);
        $this->assertFalse($result['repaired']);
        $this->assertFalse($mirror->wasSaved);
    }

    private function reconciler(): BemTargetMediaReconciler
    {
        return new BemTargetMediaReconciler(
            Mockery::mock('App\Services\Shopify\BemWatermark\BemShopifyGraphqlClient'),
            Mockery::mock('App\Services\Shopify\BemWatermark\BemShopifyStagedUploadService'),
            Mockery::mock('App\Services\Shopify\BemWatermark\BemWatermarkImageProcessor'),
            Mockery::mock('App\Services\Shopify\BemWatermark\BemProductWatermarkMetafieldService'),
            app(BemImageIdentityService::class)
        );
    }

    private function backupImages(): array
    {
        return [
            [
                'position' => 1,
                'source_url' => 'https://cdn.backup.test/original-1.jpg?v=1',
                'original_extension' => 'jpg',
                'alt' => 'Image 1',
            ],
            [
                'position' => 2,
                'source_url' => 'https://cdn.backup.test/original-2.jpg?v=1',
                'original_extension' => 'jpg',
                'alt' => 'Image 2',
            ],
        ];
    }

    private function liveImages(): array
    {
        return [$this->liveImage(1), $this->liveImage(2)];
    }

    private function liveImage(int $position): array
    {
        return [
            'id' => 'gid://shopify/MediaImage/'.$position,
            'url' => "https://cdn.target.test/watermarked-{$position}.jpg?v=2",
            'altText' => "Image {$position}",
        ];
    }

    private function matchingManifest(): array
    {
        return [
            'status' => 'completed',
            'images' => [$this->manifestImage(1), $this->manifestImage(2)],
        ];
    }

    private function manifestImage(int $position): array
    {
        return [
            'position' => $position,
            'source_url' => "https://cdn.backup.test/original-{$position}.jpg?v=7",
            'watermarked_url' => "https://cdn.target.test/watermarked-{$position}.jpg?v=9",
            'filename' => "watermarked-{$position}.jpg",
            'original_extension' => 'jpg',
            'status' => 'completed',
        ];
    }

    private function processedImage(int $position): array
    {
        return [
            'position' => $position,
            'source_url' => "https://cdn.backup.test/original-{$position}.jpg?v=1",
            'watermarked_url' => null,
            'filename' => "watermarked-{$position}.jpg",
            'original_extension' => 'jpg',
            'status' => 'processed',
            'path' => "/tmp/bem-target-{$position}.jpg",
            'mime' => 'image/jpeg',
            'alt' => "Image {$position}",
        ];
    }

    private function uploadedImage(int $position): array
    {
        return array_merge($this->processedImage($position), [
            'media_id' => 'gid://shopify/MediaImage/'.$position,
            'watermarked_url' => "https://cdn.target.test/watermarked-{$position}.jpg?v=10",
            'status' => 'completed',
        ]);
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

    private function mirror(): TestSavingProductMirror
    {
        $mirror = new TestSavingProductMirror();
        $mirror->source_shop_id = 3;
        $mirror->source_product_id = 700;
        $mirror->source_product_gid = 'gid://shopify/Product/700';
        $mirror->target_shop_id = 5;
        $mirror->target_product_id = 900;
        $mirror->target_product_gid = 'gid://shopify/Product/900';
        $mirror->last_snapshot = [];

        return $mirror;
    }
}

class TestSavingProductMirror extends ProductMirror
{
    public bool $wasSaved = false;

    public function save(array $options = []): bool
    {
        $this->wasSaved = true;

        return true;
    }
}
