<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Shopify\BemWatermark\BemBackupManifestService;
use App\Services\Shopify\BemWatermark\BemSourceUpdateImageClassifier;
use Illuminate\Support\Facades\Http;
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
}
