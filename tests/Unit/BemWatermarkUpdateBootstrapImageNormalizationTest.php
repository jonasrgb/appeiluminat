<?php

namespace Tests\Unit;

use App\Services\Shopify\BemWatermark\BemWatermarkUpdateBootstrapService;
use ReflectionClass;
use Tests\TestCase;

class BemWatermarkUpdateBootstrapImageNormalizationTest extends TestCase
{
    public function test_it_normalizes_graphql_image_connection_nodes(): void
    {
        $reflection = new ReflectionClass(BemWatermarkUpdateBootstrapService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('normalizeImages');
        $method->setAccessible(true);

        $images = $method->invoke($service, [
            'nodes' => [
                [
                    'id' => 'gid://shopify/MediaImage/1',
                    'url' => 'https://cdn.shopify.test/clean-1.png',
                    'altText' => 'Prima imagine',
                ],
                [
                    'id' => 'gid://shopify/MediaImage/2',
                    'url' => 'https://cdn.shopify.test/clean-2.png',
                    'altText' => null,
                ],
            ],
        ]);

        $this->assertCount(2, $images);
        $this->assertSame('gid://shopify/MediaImage/1', $images[0]['id']);
        $this->assertSame('https://cdn.shopify.test/clean-1.png', $images[0]['url']);
        $this->assertSame('Prima imagine', $images[0]['alt']);
        $this->assertSame(2, $images[1]['position']);
    }

    public function test_it_keeps_support_for_flat_webhook_image_arrays(): void
    {
        $reflection = new ReflectionClass(BemWatermarkUpdateBootstrapService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('normalizeImages');
        $method->setAccessible(true);

        $images = $method->invoke($service, [
            [
                'id' => 123,
                'src' => 'https://cdn.shopify.test/webhook.jpg',
                'alt' => 'Webhook image',
            ],
        ]);

        $this->assertCount(1, $images);
        $this->assertSame('https://cdn.shopify.test/webhook.jpg', $images[0]['url']);
        $this->assertSame('Webhook image', $images[0]['alt']);
    }
}
