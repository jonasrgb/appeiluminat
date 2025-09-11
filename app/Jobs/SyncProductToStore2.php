<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductOption;
use App\Models\ProductImage;


class SyncProductToStore2 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $productData;

    public function __construct(array $productData)
    {
        $this->productData = $productData;
    }

    public function handle(): void
    {

        $config = [
            'shop' => env('STORE_URL_POWERLED'),
            'access_token' => env('ACCESS_TOKEN_ADMIN_POWER'),
            'location_id' => env('LOCATION_POWERLEDS'),
        ];  

        $configStore1 = [
            'shop' => env('SHOPIFY_SHOP_EILUMINAT_URL'),
            'access_token' => env('ACCESS_TOKEN_ADMIN_EILUMINAT'),
        ];

        Log::debug('🌍 ENV STORE_URL_POWERLED: ' . env('STORE_URL_POWERLED'));
        Log::debug('🧩 Variants to create:', $this->productData['variants']);
        $client = new Client([
            'base_uri' => "https://{$config['shop']}/admin/api/2024-01/graphql.json",
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Shopify-Access-Token' => $config['access_token']
            ]
        ]);
        Log::info('🏁 SyncProductToStore2 start', [
            'target_shop' => $config['shop'],
            'product_id' => $this->productData['id'] ?? null,
            'title' => $this->productData['title'] ?? null,
            'handle' => $this->productData['handle'] ?? null,
            'variants_count' => isset($this->productData['variants']) ? count($this->productData['variants']) : 0,
            'options_count' => isset($this->productData['options']) ? count($this->productData['options']) : 0,
        ]);
        
        Log::debug("🧠 Re-fetching using Store1 Product ID: {$this->productData['id']}");
        // ✅ INSERTAT AICI
        if (count($this->productData['variants']) === 1 && count($this->productData['options']) > 1) {
            Log::info("⏳ Re-fetching all variants from Store1 because product has multiple options...");

            sleep(2); // ✅ Adăugăm întârziere de 2 secunde

            $fetchedVariants = $this->reFetchProductVariantsFromStore1($this->productData['id'], $configStore1);
            Log::debug("📦 Shopify re-fetch raw response:", $fetchedVariants);

            if (!empty($fetchedVariants)) {
                $this->productData['variants'] = $fetchedVariants;
                Log::info("✅ Variants after re-fetch:", $this->productData['variants']);
            } else {
                Log::warning("⚠️ Re-fetch failed. Keeping original variants.");
            }
        }

        $productPayload = [
            'query' => 'mutation CreateProduct($input: ProductInput!) {
                productCreate(input: $input) {
                    product {
                        id
                        title
                        handle
                        variants(first: 100) {
                        edges {
                            node {
                            id
                            sku
                            selectedOptions {
                                name
                                value
                            }
                            }
                        }
                        }
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }',
            'variables' => [
                'input' => [
                    'title' => $this->productData['title'],
                    'handle' => $this->productData['handle'],
                    'bodyHtml' => $this->productData['body_html'],
                    'productType' => $this->productData['product_type'],
                    'vendor' => $this->productData['vendor'],
                    'tags' => explode(', ', $this->productData['tags']),
                    'options' => array_column($this->productData['options'], 'name'),
                    'variants' => array_map(function($variant) use ($config) {
                        return [
                            'title' => $variant['title'],
                            'sku' => $variant['sku'],
                            'price' => $variant['price'],
                            'compareAtPrice' => $variant['compare_at_price'],
                            'inventoryPolicy' => strtoupper($variant['inventory_policy']),
                            'barcode' => $variant['barcode'],
                            'taxable' => $variant['taxable'],
                            'options' => array_filter([
                                $variant['option1'] ?? null,
                                $variant['option2'] ?? null,
                                $variant['option3'] ?? null,
                            ]),
                            'weight' => $variant['weight'] ?? null,
                            'weightUnit' => $variant['weight_unit'] ?? 'KILOGRAMS',
                            'inventoryItem' => [
                                'tracked' => true,
                                'cost' => 0,
                            ],
                            'inventoryQuantities' => [
                                [
                                    'locationId' => "gid://shopify/Location/{$config['location_id']}",
                                    'availableQuantity' => 99999
                                ]
                            ]
                        ];
                    }, $this->productData['variants']),
                    'status' => strtoupper($this->productData['status']),
                ]
            ]
        ];

        try {
            $response = $client->post('', ['json' => $productPayload]);
            $status = $response->getStatusCode();
            $responseData = json_decode($response->getBody(), true);
            Log::info("🛒 Store2: productCreate status {$status}");
            if (isset($responseData['errors'])) {
                Log::error('❌ GraphQL top-level errors', $responseData['errors']);
            }
            $userErrors = $responseData['data']['productCreate']['userErrors'] ?? [];
            if (!empty($userErrors)) {
                Log::error('❌ productCreate userErrors', $userErrors);
            }
            Log::debug("📦 productCreate response", $responseData);
        } catch (\Exception $e) {
            Log::error('❌ Exception during productCreate: ' . $e->getMessage());
            return; // stop here if creation failed entirely
        }
        $variantsPayload = $this->productData['variants'];

        $parentVariants = [];
        foreach ($variantsPayload as $variant) {
            $parentVariants[] = [
                'id' => $variant['id'],
                'sku' => $variant['sku'],
                'option1' => $variant['option1'] ?? null,
                'option2' => $variant['option2'] ?? null,
                'option3' => $variant['option3'] ?? null,
                'image_id' => $variant['image_id'] ?? null,
            ];
        }
        
        Log::info("📦 Parent Variants:", $parentVariants);
        $store2Variants = [];
        foreach ($responseData['data']['productCreate']['product']['variants']['edges'] ?? [] as $edge) {
            $selectedOptions = collect($edge['node']['selectedOptions'])->pluck('value')->values();

            $store2Variants[] = [
                'id' => (int) str_replace('gid://shopify/ProductVariant/', '', $edge['node']['id']),
                'sku' => $edge['node']['sku'],
                'option1' => $selectedOptions[0] ?? null,
                'option2' => $selectedOptions[1] ?? null,
                'option3' => $selectedOptions[2] ?? null,
            ];
        }

        Log::info("🆕 Newly Created Variants from Shopify:", $store2Variants);
        $this->addParentVariantMetafieldsToVariants($store2Variants, $parentVariants, $config);

        if (!empty($responseData['data']['productCreate']['product']['id'])) {
            $newProductId = $responseData['data']['productCreate']['product']['id'];

            $this->addParentMetafieldToProduct($newProductId, $this->productData['id'], $config);

            if (!empty($this->productData['images'])) {
                $this->uploadImagesToStore2($newProductId, $this->productData['images'], $config);
                sleep(3);
            }

             $this->assignImagesToVariants($store2Variants, $parentVariants, $config, $newProductId);

            // Optionally: publish to channels
            $this->publishProductToAllChannels($newProductId);
        }
    }

    private function uploadImagesToStore2(string $productId, array $images, array $config)
    {
        $client = new Client([
            'base_uri' => "https://{$config['shop']}/admin/api/2024-01/graphql.json",
            'headers' => [
                'X-Shopify-Access-Token' => $config['access_token'],
                'Content-Type' => 'application/json'
            ]
        ]);

        foreach ($images as $image) {
            $query = 'mutation productCreateMedia($media: [CreateMediaInput!]!, $productId: ID!) {
                productCreateMedia(media: $media, productId: $productId) {
                    media {
                        alt
                        mediaContentType
                        status
                    }
                    mediaUserErrors {
                        field
                        message
                    }
                    product {
                        id
                        title
                    }
                }
            }';

            $variables = [
                'productId' => $productId,
                'media' => [[
                    'alt' => $image['alt'] ?? '',
                    'mediaContentType' => 'IMAGE',
                    'originalSource' => $image['src'],
                ]]
            ];

            try {
                $response = $client->post('', [
                    'json' => [
                        'query' => $query,
                        'variables' => $variables
                    ]
                ]);

                $body = json_decode($response->getBody(), true);

                if (!empty($body['data']['productCreateMedia']['media'][0]['status'])) {
                    //Log::info("✅ Image uploaded successfully to Store2: ", $body['data']['productCreateMedia']['media'][0]);
                    Log::info("✅ Image uploaded successfully powerled:");
                } else {
                    //Log::warning("⚠️ Image upload issue for Store2: ", $body);
                    Log::warning("⚠️ Image upload issue for powerled:");
                }

            } catch (\Exception $e) {
                Log::error("❌ Store2 image upload exception: " . $e->getMessage());
            }
        }
    }

    private function publishProductToAllChannels($productId)
    {
        $publicationIds = $this->getAllPublications();

        if (empty($publicationIds)) {
            Log::warning("❌ No publications found. Product not published.");
            return;
        }

        foreach ($publicationIds as $publicationId) {
            $mutation = 'mutation PublishProduct($productId: ID!, $publicationId: ID!) {
                publishablePublish(id: $productId, input: { publicationId: $publicationId }) {
                    publishable {
                        publicationCount
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }';

            $variables = [
                'productId' => $productId,
                'publicationId' => $publicationId
            ];

            try {
                $response = Http::withHeaders([
                    'X-Shopify-Access-Token' => env('ACCESS_TOKEN_ADMIN_POWER'),
                    'Content-Type' => 'application/json'
                ])->post("https://" . env('STORE_URL_POWERLED') . "/admin/api/2024-01/graphql.json", [
                    'query' => $mutation,
                    'variables' => $variables
                ]);

                $body = $response->json();

                //Log::info("🔍 Shopify Publish Response for publication $publicationId:", $body);

                if (!empty($body['data']['publishablePublish']['userErrors'])) {
                    //Log::error("❌ Publishing error for publication $publicationId:", $body['data']['publishablePublish']['userErrors']);
                } else {
                    //Log::info("✅ Successfully published product $productId to publication $publicationId");
                }
            } catch (\Exception $e) {
                Log::error("❌ Error publishing product $productId: " . $e->getMessage());
            }
        }

        // Check if the product is actually published
        //$this->debugCheckProductPublication($productId);
    }

    private function getAllPublications()
    {
        $query = '{
            publications(first: 10) {
                edges {
                    node {
                        id
                        name
                    }
                }
            }
        }';

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => env('ACCESS_TOKEN_ADMIN_POWER'),
                'Content-Type' => 'application/json'
            ])->post("https://" . env('STORE_URL_POWERLED') . "/admin/api/2024-01/graphql.json", [
                'query' => $query
            ]);

            $body = $response->json();

            if (isset($body['data']['publications']['edges'])) {
                return array_map(fn($edge) => $edge['node']['id'], $body['data']['publications']['edges']);
            }
        } catch (\Exception $e) {
            Log::error("Error fetching publications: " . $e->getMessage());
        }

        return [];
    }

    private function addParentMetafieldToProduct(string $shopifyProductId, int $parentId, array $config)
    {
        $client = new Client([
            'base_uri' => "https://{$config['shop']}/admin/api/2025-01/graphql.json",
            'headers' => [
                'X-Shopify-Access-Token' => $config['access_token'],
                'Content-Type' => 'application/json',
            ]
        ]);

        $mutation = 'mutation metafieldSet($metafields: [MetafieldsSetInput!]!) {
            metafieldsSet(metafields: $metafields) {
                metafields {
                    id
                    namespace
                    key
                    value
                }
                userErrors {
                    field
                    message
                }
            }
        }';

        $variables = [
            'metafields' => [[
                'ownerId' => $shopifyProductId,
                'namespace' => 'custom',
                'key' => 'parentproduct',
                'type' => 'number_integer',
                'value' => (string)$parentId,
            ]]
        ];

        try {
            $response = $client->post('', [
                'json' => [
                    'query' => $mutation,
                    'variables' => $variables
                ]
            ]);

            $body = json_decode($response->getBody(), true);

            if (!empty($body['data']['metafieldsSet']['metafields'])) {
                Log::info("📝 Metafield 'parentproduct' set successfully on product.");
            } else {
                Log::warning("⚠️ Failed to set 'parentproduct' metafield:", $body['data']['metafieldsSet']['userErrors']);
            }
        } catch (\Exception $e) {
            Log::error("❌ Error setting 'parentproduct' metafield: " . $e->getMessage());
        }
    }

    
    private function addParentVariantMetafieldsToVariants(array $store2Variants, array $parentVariants, array $config)
    {
        $client = new Client([
            'base_uri' => "https://{$config['shop']}/admin/api/2024-01/graphql.json",
            'headers' => [
                'X-Shopify-Access-Token' => $config['access_token'],
                'Content-Type' => 'application/json',
            ]
        ]);

        $mutation = <<<'GRAPHQL'
        mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
            metafieldsSet(metafields: $metafields) {
                metafields {
                    key
                    value
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        foreach ($store2Variants as $store2Variant) {
            $matchingParent = null;

            // 1. Match pe SKU doar dacă e setat
            if (!empty($store2Variant['sku'])) {
                $matchingParent = collect($parentVariants)->firstWhere('sku', $store2Variant['sku']);
                if ($matchingParent) {
                    Log::debug("✅ Matched by SKU: Store2 Variant ID {$store2Variant['id']} => Parent Variant ID {$matchingParent['id']}");
                }
            }

            // 2. Dacă nu s-a potrivit prin SKU, încearcă după opțiuni
            if (!$matchingParent) {
                $matchingParent = collect($parentVariants)->first(function ($parent) use ($store2Variant) {
                    return (
                        ($parent['option1'] ?? null) === ($store2Variant['option1'] ?? null) &&
                        ($parent['option2'] ?? null) === ($store2Variant['option2'] ?? null) &&
                        ($parent['option3'] ?? null) === ($store2Variant['option3'] ?? null)
                    );
                });

                if ($matchingParent) {
                    Log::debug("🔁 Matched by OPTIONS: Store2 Variant ID {$store2Variant['id']} => Parent Variant ID {$matchingParent['id']} (" .
                        ($store2Variant['option1'] ?? '-') . " / " .
                        ($store2Variant['option2'] ?? '-') . " / " .
                        ($store2Variant['option3'] ?? '-') . ")");
                }
            }

            // 3. Dacă niciun match, log de eroare și continuă
            if (!$matchingParent) {
                Log::warning("❌ No match for Store2 Variant ID {$store2Variant['id']} (SKU: {$store2Variant['sku']}), Options: " .
                    ($store2Variant['option1'] ?? '-') . " / " .
                    ($store2Variant['option2'] ?? '-') . " / " .
                    ($store2Variant['option3'] ?? '-'));
                continue;
            }

            // 4. Trimite metafieldul corect
            $variables = [
                'metafields' => [[
                    'ownerId' => 'gid://shopify/ProductVariant/' . $store2Variant['id'],
                    'namespace' => 'custom',
                    'key' => 'parentvariant',
                    'type' => 'number_integer',
                    'value' => (string) $matchingParent['id'],
                ]]
            ];

            try {
                $response = $client->post('', [
                    'json' => [
                        'query' => $mutation,
                        'variables' => $variables
                    ]
                ]);

                $body = json_decode($response->getBody(), true);

                if (!empty($body['data']['metafieldsSet']['metafields'])) {
                    Log::info("🔗 Metafield 'parentvariant' set for Store 2 Variant ID: {$store2Variant['id']}");
                } else {
                    Log::warning("⚠️ Failed to set metafield for variant ID {$store2Variant['id']}: ", $body['data']['metafieldsSet']['userErrors']);
                }
            } catch (\Exception $e) {
                Log::error("❌ Error setting parentvariant metafield for variant ID {$store2Variant['id']}: " . $e->getMessage());
            }
        }
    }

private function assignImagesToVariants(array $store2Variants, array $parentVariants, array $config, string $productId)
{
    $graphqlClient = new \GuzzleHttp\Client([
        'base_uri' => "https://{$config['shop']}/admin/api/2024-01/graphql.json",
        'headers' => [
            'X-Shopify-Access-Token' => $config['access_token'],
            'Content-Type' => 'application/json',
        ],
    ]);

    $mutation = <<<'GRAPHQL'
    mutation updateVariant($input: ProductVariantInput!) {
        productVariantUpdate(input: $input) {
            productVariant {
                id
            }
            userErrors {
                field
                message
            }
        }
    }
    GRAPHQL;

    Log::debug("🖼️ All original images from productData", $this->productData['images']);

    // 🔄 Obține imagini din Store2 (REST) — comentat și înlocuit cu GraphQL
    // $response = (new \GuzzleHttp\Client([
    //     'base_uri' => "https://{$config['shop']}/admin/api/2024-01/",
    //     'headers' => [
    //         'X-Shopify-Access-Token' => $config['access_token'],
    //         'Content-Type' => 'application/json',
    //     ],
    // ]))->get("products/" . $this->extractProductNumericId($productId) . "/images.json");
    // $store2Images = json_decode($response->getBody()->getContents(), true)['images'] ?? [];

    // ✅ Obține imagini din Store2 folosind GraphQL (echivalent funcțional)
    try {
        $imagesQuery = <<<'GRAPHQL'
        query getProductImages($id: ID!) {
            product(id: $id) {
                images(first: 250) {
                    edges {
                        node {
                            id
                            altText
                        }
                    }
                }
            }
        }
        GRAPHQL;

        $imagesResp = $graphqlClient->post('', [
            'json' => [
                'query' => $imagesQuery,
                'variables' => ['id' => $productId]
            ]
        ]);

        $imagesBody = json_decode($imagesResp->getBody(), true);
        $edges = $imagesBody['data']['product']['images']['edges'] ?? [];
        $store2Images = array_map(function($edge) {
            $node = $edge['node'];
            return [
                // extragem ID numeric pentru compatibilitate cu restul codului
                'id' => (int) str_replace('gid://shopify/ProductImage/', '', $node['id']),
                'alt' => $node['altText'] ?? null,
            ];
        }, $edges);
    } catch (\Exception $e) {
        Log::error("❌ Error fetching images via GraphQL: " . $e->getMessage());
        $store2Images = [];
    }

    // 🔗 Creează mapare după position (cheie: id vechi → id nou)
    $imageMap = [];
    foreach ($this->productData['images'] as $originalImage) {
        $match = collect($store2Images)->firstWhere('alt', $originalImage['alt']); // sau 'position' dacă alt e duplicat
        if ($match) {
            $imageMap[$originalImage['id']] = $match['id'];
        }
    }

    foreach ($store2Variants as $variant) {
        $parent = collect($parentVariants)->first(function ($v) use ($variant) {
            return (
                ($v['option1'] ?? null) === ($variant['option1'] ?? null) &&
                ($v['option2'] ?? null) === ($variant['option2'] ?? null) &&
                ($v['option3'] ?? null) === ($variant['option3'] ?? null)
            );
        });

        if (!$parent || empty($parent['image_id'])) {
            continue;
        }

        $imageIdNewStore = $imageMap[$parent['image_id']] ?? null;

        if (!$imageIdNewStore) {
            Log::warning("⚠️ No matching image in Store2 for parent image_id {$parent['image_id']}");
            continue;
        }

        Log::debug("🎯 Assigning image ID {$imageIdNewStore} to variant ID {$variant['id']}");

        $variables = [
            'input' => [
                'id' => 'gid://shopify/ProductVariant/' . $variant['id'],
                'imageId' => 'gid://shopify/ProductImage/' . $imageIdNewStore,
            ]
        ];

        try {
            $response = $graphqlClient->post('', [
                'json' => [
                    'query' => $mutation,
                    'variables' => $variables
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            if (!empty($data['data']['productVariantUpdate']['productVariant']['id'])) {
                Log::info("✅ Image assigned to variant ID {$variant['id']}");
            } else {
                Log::warning("⚠️ Failed to assign image to variant ID {$variant['id']}", $data['data']['productVariantUpdate']['userErrors'] ?? []);
            }
        } catch (\Exception $e) {
            Log::error("❌ Error assigning image to variant ID {$variant['id']}: " . $e->getMessage());
        }
    }
}

    private function extractProductNumericId(string $gid): string
    {
        // Example: gid://shopify/Product/15370128195919 → return 15370128195919
        return (string) last(explode('/', $gid));
    }

    private function reFetchProductVariantsFromStore1(int $productIdStore1, array $config): array
    {
        $client = new Client([
            'base_uri' => "https://{$config['shop']}/admin/api/2024-01/",
            'headers' => [
                'X-Shopify-Access-Token' => $config['access_token'],
                'Content-Type' => 'application/json'
            ]
        ]);

        
        $query = <<<'GRAPHQL'
        query getProduct($id: ID!) {
            product(id: $id) {
                variants(first: 100) {
                    edges {
                        node {
                            id
                            title
                            sku
                            price
                            compareAtPrice
                            barcode
                            inventoryPolicy
                            taxable
                            weight
                            weightUnit
                            selectedOptions {
                                name
                                value
                            }
                            metafields(first: 5, namespace: "custom") {
                                edges {
                                    node {
                                        key
                                        value
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        GRAPHQL;

        $gid = "gid://shopify/Product/{$productIdStore1}";

        try {
            $response = $client->post('', [
                'json' => [
                    'query' => $query,
                    'variables' => ['id' => $gid]
                ]
            ]);

            $body = json_decode($response->getBody(), true);

            // 🔍 Debug complet pentru răspunsul brut de la Shopify
            Log::debug("📦 Shopify re-fetch raw GraphQL body for GID {$gid}:", ['body' => $body]);

            $edges = $body['data']['product']['variants']['edges'] ?? [];

            if (empty($edges)) {
                Log::warning("⚠️ No variants found in Shopify response for GID: {$gid}");
            }

            return collect($edges)->map(function ($edge) {
                $variant = $edge['node'];
                $options = collect($variant['selectedOptions'])->pluck('value')->all();

                return [
                    'id' => (int) str_replace('gid://shopify/ProductVariant/', '', $variant['id']),
                    'title' => $variant['title'],
                    'sku' => $variant['sku'],
                    'price' => $variant['price'],
                    'compare_at_price' => $variant['compareAtPrice'],
                    'barcode' => $variant['barcode'],
                    'inventory_policy' => strtolower($variant['inventoryPolicy']),
                    'taxable' => $variant['taxable'],
                    'option1' => $options[0] ?? null,
                    'option2' => $options[1] ?? null,
                    'option3' => $options[2] ?? null,
                    'weight' => $variant['weight'] ?? null,
                    'weight_unit' => $variant['weightUnit'] ?? 'KILOGRAMS',
                ];
            })->toArray();

        } catch (\Exception $e) {
            Log::error("❌ Error fetching full variants from Store1 for GID {$gid}: " . $e->getMessage());
            return $this->productData['variants']; // fallback la varianta originală
        }
    }

    
}
