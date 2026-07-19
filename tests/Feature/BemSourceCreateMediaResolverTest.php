<?php

namespace Tests\Feature;

use App\Jobs\BemApplySourceProductWatermark;
use App\Models\Shop;
use App\Services\Shopify\BemWatermark\BemSourceCreateMediaResolver;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class BemSourceCreateMediaResolverTest extends TestCase
{
    public function test_it_returns_ready_images_from_the_live_source_product(): void
    {
        Http::fake([$this->endpoint() => Http::response(['data' => ['product' => ['media' => ['nodes' => [[
            'id' => 'gid://shopify/MediaImage/1',
            'mediaContentType' => 'IMAGE',
            'status' => 'READY',
            'alt' => 'Front image',
            'image' => ['url' => 'https://cdn.shopify.test/source/front.png'],
            'preview' => ['image' => ['url' => 'https://cdn.shopify.test/source/front.png']],
        ]]]]]])]);

        $result = app(BemSourceCreateMediaResolver::class)->resolve(
            $this->sourceShop(),
            'gid://shopify/Product/100'
        );

        $this->assertSame('ready', $result['status']);
        $this->assertSame('https://cdn.shopify.test/source/front.png', $result['images'][0]['src']);
        $this->assertSame('gid://shopify/MediaImage/1', $result['images'][0]['admin_graphql_api_id']);
    }

    public function test_it_reports_processing_until_every_source_image_is_ready(): void
    {
        Http::fake([$this->endpoint() => Http::response(['data' => ['product' => ['media' => ['nodes' => [[
            'id' => 'gid://shopify/MediaImage/2',
            'mediaContentType' => 'IMAGE',
            'status' => 'PROCESSING',
            'alt' => null,
            'image' => null,
            'preview' => ['image' => null],
        ]]]]]])]);

        $result = app(BemSourceCreateMediaResolver::class)->resolve(
            $this->sourceShop(),
            'gid://shopify/Product/100'
        );

        $this->assertSame('processing', $result['status']);
        $this->assertSame([], $result['images']);
    }

    public function test_it_distinguishes_a_product_with_no_media(): void
    {
        Http::fake([$this->endpoint() => Http::response(['data' => ['product' => ['media' => ['nodes' => []]]]])]);

        $result = app(BemSourceCreateMediaResolver::class)->resolve(
            $this->sourceShop(),
            'gid://shopify/Product/100'
        );

        $this->assertSame('empty', $result['status']);
        $this->assertSame([], $result['images']);
    }

    public function test_source_watermark_job_hydrates_media_missing_from_create_webhook(): void
    {
        Http::fake([$this->endpoint() => Http::response(['data' => ['product' => ['media' => ['nodes' => [[
            'id' => 'gid://shopify/MediaImage/91',
            'mediaContentType' => 'IMAGE',
            'status' => 'READY',
            'alt' => 'Delayed source image',
            'image' => ['url' => 'https://cdn.shopify.test/source/clean.jpg?v=1'],
            'preview' => ['image' => ['url' => 'https://cdn.shopify.test/source/clean.jpg?v=1']],
        ]]]]]])]);

        $job = new BemApplySourceProductWatermark(
            sourceShopId: 3,
            sourceProductId: 123,
            sourceProductGid: 'gid://shopify/Product/123',
            title: 'Delayed media product',
            sourcePayload: ['id' => 123, 'images' => []]
        );

        $method = new ReflectionMethod($job, 'hydrateDelayedSourceMedia');
        $this->assertTrue($method->invoke(
            $job,
            $this->sourceShop(),
            app(BemSourceCreateMediaResolver::class)
        ));
        $this->assertSame(
            'https://cdn.shopify.test/source/clean.jpg?v=1',
            $job->sourcePayload['images'][0]['src']
        );
    }

    public function test_create_webhook_dispatcher_does_not_drop_empty_source_media_payloads(): void
    {
        $method = new ReflectionMethod(
            \App\Jobs\ProcessShopifyWebhook::class,
            'dispatchSourceWatermarkForCreate'
        );
        $lines = file(app_path('Jobs/ProcessShopifyWebhook.php'));
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringNotContainsString('if (empty($sourceImages))', $source);
        $this->assertStringContainsString('BemApplySourceProductWatermark::dispatch(', $source);
    }

    private function sourceShop(): Shop
    {
        return new Shop([
            'domain' => 'eiluminat.myshopify.com',
            'access_token' => 'test-token',
            'api_version' => '2025-01',
        ]);
    }

    private function endpoint(): string
    {
        return 'https://eiluminat.myshopify.com/admin/api/2025-01/graphql.json';
    }
}
