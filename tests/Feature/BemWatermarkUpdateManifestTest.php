<?php

namespace Tests\Feature;

use App\Jobs\BemSyncBackupManifestFromSourceUpdate;
use App\Models\ProductMirror;
use App\Models\Shop;
use App\Services\Shopify\BemWatermark\BemBackupManifestService;
use App\Services\Shopify\BemWatermark\BemImageIdentityService;
use App\Services\Shopify\BemWatermark\BemSourceUpdateImageClassifier;
use App\Services\Shopify\BemWatermark\BemTargetMediaReconciler;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class BemWatermarkUpdateManifestTest extends TestCase
{
    public function test_backup_manifest_service_reads_and_writes_watermark_manifest(): void
    {
        $backup = $this->shop(6, 'eiluminatbackup.myshopify.com');
        $writtenPayload = null;

        Http::fake(function ($request) use (&$writtenPayload) {
            $body = $request->data();
            $query = (string) ($body['query'] ?? '');

            if (str_contains($query, 'BemBackupWatermarkManifest')) {
                return Http::response([
                    'data' => [
                        'product' => [
                            'metafield' => [
                                'value' => json_encode([
                                    'version' => 1,
                                    'images' => [[
                                        'image_uuid' => 'bem_existing01',
                                        'status' => 'active',
                                        'position' => 2,
                                    ]],
                                ], JSON_UNESCAPED_SLASHES),
                            ],
                        ],
                    ],
                ]);
            }

            if (str_contains($query, 'BemSetBackupWatermarkManifest')) {
                $writtenPayload = json_decode($body['variables']['metafields'][0]['value'], true);

                return Http::response([
                    'data' => [
                        'metafieldsSet' => [
                            'metafields' => [['id' => 'gid://shopify/Metafield/manifest']],
                            'userErrors' => [],
                        ],
                    ],
                ]);
            }

            return Http::response(['errors' => [['message' => 'Unexpected request']]], 500);
        });

        $service = app(BemBackupManifestService::class);
        $manifest = $service->fetch($backup, 'gid://shopify/Product/456');
        $manifest = $service->appendHistory($manifest, 'test_event', ['image_uuid' => 'bem_existing01']);
        $service->update($backup, 'gid://shopify/Product/456', $manifest);

        $this->assertSame('eiluminatbackup.myshopify.com', $manifest['backup_shop']);
        $this->assertSame('gid://shopify/Product/456', $manifest['backup_product_gid']);
        $this->assertSame('bem_existing01', $manifest['images'][0]['image_uuid']);
        $this->assertIsArray($writtenPayload);
        $this->assertSame('test_event', $writtenPayload['history'][0]['event']);
        $this->assertSame('bem_existing01', $writtenPayload['history'][0]['image_uuid']);
    }

    public function test_source_update_classifier_detects_existing_deleted_and_new_clean_images(): void
    {
        $manifest = ['images' => []];
        for ($position = 1; $position <= 11; $position++) {
            $uuid = sprintf('bem_img%02d', $position);
            $manifest['images'][] = [
                'image_uuid' => $uuid,
                'status' => 'active',
                'position' => $position,
                'backup_media_gid' => 'gid://shopify/MediaImage/backup'.$position,
                'backup_url' => "https://cdn.backup.test/bem_{$uuid}_original_p_{$position}.jpg",
                'source_watermarked_media_gid' => 'gid://shopify/MediaImage/source'.$position,
                'source_watermarked_url' => "https://cdn.source.test/eiluminat_{$uuid}_w_p_{$position}.jpg?v=old",
            ];
        }

        $sourceImages = [];
        foreach ([1, 2, 3, 5, 6, 8, 9, 10, 11] as $position) {
            $uuid = sprintf('bem_img%02d', $position);
            $sourceImages[] = [
                'position' => count($sourceImages) + 1,
                'url' => "https://cdn.source.test/eiluminat_{$uuid}_w_p_{$position}.jpg?v=new",
                'media_gid' => 'gid://shopify/MediaImage/source'.$position,
            ];
        }

        $sourceImages[] = [
            'position' => 10,
            'url' => 'https://cdn.source.test/new-clean-12.png?v=1',
            'media_gid' => 'gid://shopify/MediaImage/new12',
        ];
        $sourceImages[] = [
            'position' => 11,
            'url' => 'https://cdn.source.test/new-clean-13.webp?v=1',
            'media_gid' => 'gid://shopify/MediaImage/new13',
        ];

        $result = app(BemSourceUpdateImageClassifier::class)->classify($sourceImages, $manifest);

        $this->assertCount(9, $result['existing']);
        $this->assertCount(2, $result['new_clean']);
        $this->assertCount(2, $result['deleted']);
        $this->assertSame([], $result['unknown_watermarked']);
        $this->assertSame(['bem_img04', 'bem_img07'], array_values(array_map(
            static fn ($image) => $image['image_uuid'],
            $result['deleted']
        )));
        $this->assertSame('png', $result['new_clean'][0]['original_extension']);
        $this->assertSame('webp', $result['new_clean'][1]['original_extension']);
    }

    public function test_source_update_classifier_blocks_unknown_watermarked_images(): void
    {
        $result = app(BemSourceUpdateImageClassifier::class)->classify([
            [
                'position' => 1,
                'url' => 'https://cdn.source.test/eiluminat_unknown-product_w_p_1.jpg',
                'media_gid' => 'gid://shopify/MediaImage/unknown',
            ],
        ], ['images' => []]);

        $this->assertSame([], $result['existing']);
        $this->assertSame([], $result['new_clean']);
        $this->assertCount(1, $result['unknown_watermarked']);
        $this->assertSame('watermarked_image_not_found_in_manifest', $result['unknown_watermarked'][0]['reason']);
    }

    public function test_source_update_reconciles_stale_history_url_with_current_clean_backup_url(): void
    {
        $job = new BemSyncBackupManifestFromSourceUpdate(
            sourceShopId: 3,
            sourceProductId: 123,
            sourceProductGid: 'gid://shopify/Product/123',
            title: 'Test product',
            sourcePayload: []
        );

        $method = new \ReflectionMethod($job, 'reconcileOriginalUrlsFromBackup');
        $method->setAccessible(true);

        $result = $method->invoke($job, [[
            'position' => 1,
            'source_url' => 'https://cdn.example.test/old-original.png?v=1',
            'previous_position' => 7,
            'matched_existing' => true,
            'original_extension' => 'png',
        ]], [[
            'position' => 7,
            'source_url' => 'https://cdn.example.test/current-original.png?v=2',
            'original_extension' => 'png',
        ]], app(BemImageIdentityService::class));

        $this->assertSame('https://cdn.example.test/current-original.png?v=2', $result[0]['source_url']);
        $this->assertTrue($result[0]['reconciled_from_backup']);
    }

    public function test_source_update_refuses_reconciliation_when_backup_position_is_missing(): void
    {
        $job = new BemSyncBackupManifestFromSourceUpdate(
            sourceShopId: 3,
            sourceProductId: 123,
            sourceProductGid: 'gid://shopify/Product/123',
            title: 'Test product',
            sourcePayload: []
        );

        $method = new \ReflectionMethod($job, 'reconcileOriginalUrlsFromBackup');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing clean backup image at recorded position 7');

        $method->invoke($job, [[
            'position' => 1,
            'source_url' => 'https://cdn.example.test/old-original.png',
            'previous_position' => 7,
            'matched_existing' => true,
        ]], [], app(BemImageIdentityService::class));
    }

    public function test_source_noop_branch_reconciles_targets_before_returning(): void
    {
        $source = $this->methodSource('handle');
        $noopStart = strpos($source, 'if ($this->isNoop(');
        $nextBranch = strpos($source, '// CDN URLs', $noopStart ?: 0);

        $this->assertNotFalse($noopStart);
        $this->assertNotFalse($nextBranch);

        $noopBranch = substr($source, $noopStart, $nextBranch - $noopStart);

        $this->assertStringContainsString('$this->reconcileTargetContexts(', $noopBranch);
        $this->assertStringContainsString('$this->throwIfTargetReconciliationFailed(', $noopBranch);
    }

    public function test_target_reconciliation_attempts_later_targets_after_one_fails(): void
    {
        $job = new BemSyncBackupManifestFromSourceUpdate(
            sourceShopId: 3,
            sourceProductId: 700,
            sourceProductGid: 'gid://shopify/Product/700',
            title: 'Test product',
            sourcePayload: []
        );
        $backup = $this->shop(6, 'eiluminatbackup.myshopify.com');
        $power = $this->shop(5, 'powerleds-ro.myshopify.com');
        $lustre = $this->shop(4, 'lustreled.myshopify.com');
        $powerMirror = $this->mirror(5, 900);
        $lustreMirror = $this->mirror(4, 901);
        $backupImages = [[
            'position' => 1,
            'source_url' => 'https://cdn.backup.test/original-1.jpg',
            'original_extension' => 'jpg',
        ]];

        $reconciler = Mockery::mock(BemTargetMediaReconciler::class);
        $reconciler->shouldReceive('reconcile')
            ->once()
            ->with($powerMirror, $power, $backup, 800, 'gid://shopify/Product/800', 'Test product', $backupImages)
            ->andThrow(new \RuntimeException('staged upload timeout'));
        $reconciler->shouldReceive('reconcile')
            ->once()
            ->with($lustreMirror, $lustre, $backup, 800, 'gid://shopify/Product/800', 'Test product', $backupImages)
            ->andReturn([
                'status' => 'healthy',
                'repaired' => false,
                'reasons' => [],
                'expected_images' => 1,
                'actual_images' => 1,
                'manifest_images' => 1,
            ]);

        $method = new \ReflectionMethod($job, 'reconcileTargetContexts');
        $method->setAccessible(true);
        $result = $method->invoke(
            $job,
            [
                ['mirror' => $powerMirror, 'target' => $power],
                ['mirror' => $lustreMirror, 'target' => $lustre],
            ],
            $reconciler,
            $backup,
            800,
            'gid://shopify/Product/800',
            $backupImages
        );

        $this->assertSame(2, $result['attempted']);
        $this->assertSame(1, $result['healthy']);
        $this->assertSame(0, $result['repaired']);
        $this->assertSame('staged upload timeout', $result['errors']['powerleds-ro.myshopify.com']);

        $throwMethod = new \ReflectionMethod($job, 'throwIfTargetReconciliationFailed');
        $throwMethod->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('powerleds-ro.myshopify.com: staged upload timeout');
        $throwMethod->invoke($job, $result);
    }

    public function test_mirror_lookup_is_scoped_to_source_shop_and_product(): void
    {
        $source = $this->methodSource('handle');

        $this->assertStringContainsString("'source_shop_id' => \$this->sourceShopId", $source);
        $this->assertStringContainsString("'source_product_id' => \$this->sourceProductId", $source);
        $this->assertStringNotContainsString(
            "ProductMirror::where('source_product_id', \$this->sourceProductId)",
            $source
        );
    }

    public function test_source_noop_repairs_a_stale_backup_manifest(): void
    {
        $job = new BemSyncBackupManifestFromSourceUpdate(
            sourceShopId: 3,
            sourceProductId: 700,
            sourceProductGid: 'gid://shopify/Product/700',
            title: 'Test product',
            sourcePayload: []
        );
        $backup = $this->shop(6, 'eiluminatbackup.myshopify.com');
        $identity = app(BemImageIdentityService::class);
        $desiredOriginalImages = [[
            'position' => 1,
            'source_url' => 'https://cdn.original.test/original-1.jpg?v=1',
            'original_extension' => 'jpg',
        ]];
        $backupImages = [[
            'position' => 1,
            'source_url' => 'https://cdn.backup.test/current-1.jpg?v=2',
            'original_extension' => 'jpg',
        ]];
        $sourceWatermarked = [
            'images' => [[
                'position' => 1,
                'source_url' => 'https://cdn.original.test/original-1.jpg?v=7',
                'watermarked_url' => 'https://cdn.source.test/watermarked-1.jpg?v=9',
                'filename' => 'watermarked-1.jpg',
                'original_extension' => 'jpg',
                'status' => 'completed',
            ]],
        ];

        $manifestService = Mockery::mock(BemBackupManifestService::class);
        $manifestService->shouldReceive('fetch')->once()->andReturn([
            'images' => [[
                'position' => 1,
                'backup_url' => 'https://cdn.backup.test/stale-1.jpg',
            ]],
        ]);
        $manifestService->shouldReceive('appendHistory')
            ->once()
            ->withArgs(function (array $manifest, string $event, array $context) {
                return $event === 'source_update_media_sync_noop_reconcile'
                    && ($manifest['images'][0]['backup_url'] ?? null) === 'https://cdn.backup.test/current-1.jpg?v=2'
                    && ($context['source_product_id'] ?? null) === 700;
            })
            ->andReturnUsing(function (array $manifest) {
                $manifest['history'] = [['event' => 'source_update_media_sync_noop_reconcile']];

                return $manifest;
            });
        $manifestService->shouldReceive('update')
            ->once()
            ->withArgs(function (Shop $shop, string $gid, array $manifest) use ($backup) {
                return $shop === $backup
                    && $gid === 'gid://shopify/Product/800'
                    && ($manifest['history'][0]['event'] ?? null) === 'source_update_media_sync_noop_reconcile';
            });

        $method = new \ReflectionMethod($job, 'persistNoopManifestIfNeeded');
        $method->setAccessible(true);
        $updated = $method->invoke(
            $job,
            $manifestService,
            $backup,
            'gid://shopify/Product/800',
            $desiredOriginalImages,
            $backupImages,
            $sourceWatermarked,
            $identity
        );

        $this->assertTrue($updated);
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

    private function mirror(int $targetShopId, int $targetProductId): ProductMirror
    {
        $mirror = new ProductMirror();
        $mirror->source_shop_id = 3;
        $mirror->source_product_id = 700;
        $mirror->source_product_gid = 'gid://shopify/Product/700';
        $mirror->target_shop_id = $targetShopId;
        $mirror->target_product_id = $targetProductId;
        $mirror->target_product_gid = 'gid://shopify/Product/'.$targetProductId;

        return $mirror;
    }

    private function methodSource(string $method): string
    {
        $reflection = new \ReflectionMethod(BemSyncBackupManifestFromSourceUpdate::class, $method);
        $lines = file(app_path('Jobs/BemSyncBackupManifestFromSourceUpdate.php'));

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}
