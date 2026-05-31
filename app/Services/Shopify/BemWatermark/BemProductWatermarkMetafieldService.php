<?php

namespace App\Services\Shopify\BemWatermark;

use App\Models\Shop;
use Illuminate\Support\Facades\Log;

class BemProductWatermarkMetafieldService
{
    public function __construct(private readonly BemShopifyGraphqlClient $graphql)
    {
    }

    public function update(Shop $target, string $productGid, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('BEM prod.watermarked payload JSON encoding failed');
        }

        $mutation = <<<'GQL'
        mutation BemSetProductWatermarked($metafields: [MetafieldsSetInput!]!) {
          metafieldsSet(metafields: $metafields) {
            metafields { id namespace key type }
            userErrors { field message }
          }
        }
        GQL;

        $response = $this->graphql->request($target, $mutation, [
            'metafields' => [[
                'ownerId' => $productGid,
                'namespace' => 'prod',
                'key' => 'watermarked',
                'type' => 'json',
                'value' => $json,
            ]],
        ]);

        $errors = $response['data']['metafieldsSet']['userErrors'] ?? [];
        if (!empty($errors)) {
            throw new \RuntimeException('BEM prod.watermarked userErrors: '.json_encode($errors));
        }

        Log::info('BEM prod.watermarked metafield updated', [
            'target_shop' => $target->domain,
            'product_gid' => $productGid,
            'images_count' => count($payload['images'] ?? []),
        ]);
    }
}
