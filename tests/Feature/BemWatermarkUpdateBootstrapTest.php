<?php

namespace Tests\Feature;

use App\Models\ProductMirror;
use App\Models\Shop;
use App\Services\Shopify\BemWatermark\BemWatermarkUpdateBootstrapService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BemWatermarkUpdateBootstrapTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->cleanupTestBemTempFiles();

        parent::tearDown();
    }

    public function test_legacy_bootstrap_reconciles_backup_from_current_clean_source_images(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required for the isolated bootstrap database test.');
        }

        $this->configureInMemoryDatabase();

        Config::set('features.bem_watermark_sync.enabled', true);
        Config::set('features.bem_watermark_sync.dry_run', false);
        Config::set('features.bem_watermark_sync.required_tag', '');
        Config::set('features.bem_watermark_sync.update_manifest_enabled', true);
        Config::set('features.bem_watermark_sync.backup_shop_domain', 'eiluminatbackup.myshopify.com');
        Config::set('features.bem_watermark_sync.target_shop_domains', []);

        $source = Shop::create([
            'id' => 3,
            'name' => 'eiluminat',
            'domain' => 'eiluminat.myshopify.com',
            'access_token' => 'source-token',
            'api_version' => '2025-01',
            'is_source' => true,
            'is_active' => true,
        ]);

        $backup = Shop::create([
            'id' => 6,
            'name' => 'backup',
            'domain' => 'eiluminatbackup.myshopify.com',
            'access_token' => 'backup-token',
            'api_version' => '2025-01',
            'is_source' => false,
            'is_active' => true,
        ]);

        ProductMirror::create([
            'source_shop_id' => $source->id,
            'source_product_id' => 8173446824236,
            'source_product_gid' => 'gid://shopify/Product/8173446824236',
            'target_shop_id' => $backup->id,
            'target_product_id' => 14901108769140,
            'target_product_gid' => 'gid://shopify/Product/14901108769140',
            'last_snapshot' => [
                'images' => [
                    ['src' => 'https://cdn.backup.test/6952.jpg', 'position' => 1],
                    ['src' => 'https://cdn.backup.test/old-extra.png', 'position' => 2],
                    ['src' => 'https://cdn.backup.test/6955.jpg', 'position' => 3],
                    ['src' => 'https://cdn.backup.test/6944.jpg', 'position' => 4],
                ],
            ],
        ]);

        $downloaded = [];
        $sourceUrls = [
            'https://cdn.source.test/6952.jpg',
            'https://cdn.source.test/6955.jpg',
            'https://cdn.source.test/6944.jpg',
            'https://cdn.source.test/new-current.jpg',
        ];

        Http::fake(function ($request) use (&$downloaded, $sourceUrls) {
            $url = $request->url();

            if (in_array($url, $sourceUrls, true)) {
                $downloaded[] = $url;

                return Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg']);
            }

            if ($url === 'https://staged.test/upload') {
                return Http::response('', 204);
            }

            $body = $request->data();
            $query = (string) ($body['query'] ?? '');

            if (str_contains($url, 'eiluminat.myshopify.com') && str_contains($query, 'BemBootstrapSourceProduct')) {
                return Http::response([
                    'data' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/8173446824236',
                            'legacyResourceId' => '8173446824236',
                            'title' => 'Candelabru vechi',
                            'handle' => 'candelabru-vechi',
                            'images' => [
                                'nodes' => array_map(
                                    static fn (string $sourceUrl, int $index) => [
                                        'id' => 'gid://shopify/MediaImage/source'.($index + 1),
                                        'url' => $sourceUrl,
                                        'altText' => null,
                                    ],
                                    $sourceUrls,
                                    array_keys($sourceUrls)
                                ),
                            ],
                            'variants' => [
                                'nodes' => [['sku' => 'SKU-OLD']],
                            ],
                            'metafield' => null,
                        ],
                    ],
                ]);
            }

            if (str_contains($url, 'eiluminatbackup.myshopify.com') && str_contains($query, 'BemBootstrapProductState')) {
                return Http::response([
                    'data' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/14901108769140',
                            'legacyResourceId' => '14901108769140',
                            'title' => 'Candelabru vechi',
                            'handle' => 'candelabru-vechi',
                            'images' => [
                                'nodes' => [
                                    ['id' => 'gid://shopify/MediaImage/old1', 'url' => 'https://cdn.backup.test/6952.jpg', 'altText' => null],
                                    ['id' => 'gid://shopify/MediaImage/old2', 'url' => 'https://cdn.backup.test/old-extra.png', 'altText' => null],
                                    ['id' => 'gid://shopify/MediaImage/old3', 'url' => 'https://cdn.backup.test/6955.jpg', 'altText' => null],
                                    ['id' => 'gid://shopify/MediaImage/old4', 'url' => 'https://cdn.backup.test/6944.jpg', 'altText' => null],
                                ],
                            ],
                        ],
                    ],
                ]);
            }

            if (str_contains($url, 'eiluminatbackup.myshopify.com') && str_contains($query, 'BemStageUploads')) {
                return Http::response([
                    'data' => [
                        'stagedUploadsCreate' => [
                            'stagedTargets' => array_fill(0, 4, [
                                'url' => 'https://staged.test/upload',
                                'resourceUrl' => 'https://cdn.backup.test/staged-current.jpg',
                                'parameters' => [['name' => 'key', 'value' => 'value']],
                            ]),
                            'userErrors' => [],
                        ],
                    ],
                ]);
            }

            if (str_contains($url, 'eiluminatbackup.myshopify.com') && str_contains($query, 'BemProductMediaIds')) {
                return Http::response([
                    'data' => [
                        'product' => [
                            'media' => [
                                'nodes' => [
                                    ['id' => 'gid://shopify/MediaImage/old1'],
                                    ['id' => 'gid://shopify/MediaImage/old2'],
                                    ['id' => 'gid://shopify/MediaImage/old3'],
                                    ['id' => 'gid://shopify/MediaImage/old4'],
                                ],
                            ],
                        ],
                    ],
                ]);
            }

            if (str_contains($url, 'eiluminatbackup.myshopify.com') && str_contains($query, 'BemReplaceProductImages')) {
                return Http::response([
                    'data' => [
                        'deleteResult' => ['deletedMediaIds' => [], 'mediaUserErrors' => []],
                        'createResult' => ['media' => [], 'mediaUserErrors' => []],
                    ],
                ]);
            }

            if (str_contains($url, 'eiluminatbackup.myshopify.com') && str_contains($query, 'BemProductWatermarkedMediaImages')) {
                return Http::response([
                    'data' => [
                        'product' => [
                            'media' => [
                                'nodes' => array_map(
                                    static fn (string $sourceUrl, int $index) => [
                                        'id' => 'gid://shopify/MediaImage/new'.($index + 1),
                                        'mediaContentType' => 'IMAGE',
                                        'status' => 'READY',
                                        'preview' => ['image' => ['url' => $sourceUrl]],
                                        'image' => ['url' => $sourceUrl],
                                    ],
                                    $sourceUrls,
                                    array_keys($sourceUrls)
                                ),
                            ],
                        ],
                    ],
                ]);
            }

            if (str_contains($query, 'BemSetProductWatermarked') || str_contains($query, 'BemBackupWatermarkManifest') || str_contains($query, 'BemSetBackupWatermarkManifest')) {
                if (str_contains($query, 'BemBackupWatermarkManifest')) {
                    return Http::response(['data' => ['product' => ['metafield' => null]]]);
                }

                return Http::response([
                    'data' => [
                        'metafieldsSet' => [
                            'metafields' => [['id' => 'gid://shopify/Metafield/test']],
                            'userErrors' => [],
                        ],
                    ],
                ]);
            }

            return Http::response(['errors' => [['message' => 'Unexpected request '.$query]]], 500);
        });

        $result = app(BemWatermarkUpdateBootstrapService::class)->bootstrap(
            source: $source,
            sourceProductId: 8173446824236,
            sourceProductGid: 'gid://shopify/Product/8173446824236',
            title: 'Candelabru vechi',
            sourcePayload: [
                'id' => 8173446824236,
                'admin_graphql_api_id' => 'gid://shopify/Product/8173446824236',
                'title' => 'Candelabru vechi',
                'tags' => 'eil-prod',
                'images' => [],
            ]
        );

        $this->assertTrue($result->didChange());
        $this->assertContains('backup_images_reconciled_from_current_source_payload', $result->context['changes']);
        $this->assertSame($sourceUrls, $downloaded);
    }

    private function cleanupTestBemTempFiles(): void
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

    private function configureInMemoryDatabase(): void
    {
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        Config::set('database.connections.sqlite.foreign_key_constraints', true);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('domain')->unique();
            $table->text('access_token');
            $table->string('api_version')->default('2025-01');
            $table->boolean('is_source')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('location_legacy_id')->nullable();
            $table->timestamps();
        });

        Schema::create('product_mirrors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_shop_id')->constrained('shops')->cascadeOnDelete();
            $table->unsignedBigInteger('source_product_id')->nullable();
            $table->string('source_product_gid')->nullable();
            $table->foreignId('target_shop_id')->constrained('shops')->cascadeOnDelete();
            $table->unsignedBigInteger('target_product_id')->nullable();
            $table->string('target_product_gid')->nullable();
            $table->json('meta')->nullable();
            $table->json('last_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['source_shop_id', 'source_product_id', 'target_shop_id'], 'src_prod_target_unique');
        });

        Schema::create('shop_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('target_shop_id')->constrained('shops')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['source_shop_id', 'target_shop_id']);
        });
    }
}
