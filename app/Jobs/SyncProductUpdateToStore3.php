<?php

namespace App\Jobs;

use App\Models\Webhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;

class SyncProductUpdateToStore3 implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    protected array $productDetails; 
    protected array $variants;
    protected array $store1Images;
    protected array $options;

    public function __construct(array $productDetails, array $variants, array $store1Images, array $options = [])
    {
        $this->productDetails = $productDetails;
        $this->variants = $variants;
        $this->store1Images = $store1Images;
        $this->options = $options;
        // Debug logs
        // Log::info('🟡 Constructor SyncProductUpdateToStore2: productDetails', $productDetails);
        //Log::info('🟡 Constructor SyncProductUpdateToStore2: variants', $variants);
        //Log::info('🟡 Constructor SyncProductUpdateToStore2: store1Images', $store1Images);
        // Log::info('🟡 Constructor SyncProductUpdateToStore2: options', $options);
    }

public function handle()
{
    $client = $this->createShopifyClient();

    $parentProductId = $this->productDetails['id'] ?? null;
    $handle = $this->productDetails['handle'] ?? null;
    $sku = $this->variants[0]['sku'] ?? null;

    //Log::info("🟡 Received Store 1 product ID: {$parentProductId}");

    if (!$parentProductId || !$handle) {
        Log::warning("⚠️ Missing required product ID or handle.");
        return;
    }

    // 1. Caută produsul după handle + metafield
    $matchedProduct = $this->findProductInStore2ByHandleAndMetafield($client, $handle, $parentProductId);

    // 2. Fallback pe SKU dacă nu a fost găsit
    if (!$matchedProduct && $sku) {
        Log::warning("❌ No product found by handle + metafield, trying SKU fallback...");
        $matchedProduct = $this->findProductByVariantSkuFallback($client, $sku);
    }

    if (!$matchedProduct) {
        Log::error("🚫 No matching product in Store 2. Sync aborted.");
        return;
    }

    $store2ProductId = $matchedProduct['id'];
    $this->updateProductInStore2($client, $store2ProductId);
    sleep(2);

    // 3. Sincronizează variantele (creează, update, delete)
    $this->syncVariants($client, $store2ProductId, $matchedProduct['variants']);
    sleep(1);

    // 4. Re-fetch actualizat cu toate variantele după sincronizare
    $refreshed = $this->findProductInStore2ByHandleAndMetafield($client, $handle, $parentProductId);

    if (!$refreshed) {
        Log::error("❌ Eroare: nu am putut reîncărca produsul după sync.");
        return;
    }

    // 5. Construiește maparea Store1 variant ID => Store2 variant GID
    $store2Map = [];

    foreach ($refreshed['variants'] as $edge) {
        $v = $edge['node'];

        // a) Încearcă pe baza metafieldului "parentvariant"
        $meta = collect($v['metafields']['edges'])->firstWhere('node.key', 'parentvariant');
        if ($meta) {
            $parentId = (int) $meta['node']['value'];
            $store2Map[$parentId] = $v['id'];
            continue;
        }

        // b) Fallback pe SKU
        if (!empty($v['sku'])) {
            $match = collect($this->variants)->firstWhere('sku', $v['sku']);
            if ($match) {
                $store2Map[$match['id']] = $v['id'];
                continue;
            }
        }

        Log::error("❌ Varianta Store2 {$v['id']} nu poate fi mapată (lipsă parentvariant și SKU).");
    }

    //Log::info("🧩 Mapare Store2 variant GIDs:", $store2Map);

    // 6. Actualizează variantele în Store2 folosind maparea
    foreach ($this->variants as $sv) {
        $id1 = $sv['id'];
        if (isset($store2Map[$id1])) {
            $id2 = $store2Map[$id1];
            Log::info("🔁 Updating Store2 variant {$id2} for Store1 variant {$id1}");
            $this->updateVariantInStore2($client, $id2, $sv);
            sleep(1);
        } else {
            Log::error("❌ Fără mapping pentru varianta Store1 {$id1}");
        }
    }

    // 7. Setează metafieldurile parentvariant (pentru fallback future)
    $config = [
        'shop' => env('STORE_URL_LUSTRELED'),
        'access_token' => env('ACCESS_TOKEN_ADMIN_LUSTRELED'),
    ];

    $this->addParentMetafieldToProduct($store2ProductId, $parentProductId, $config);
    sleep(1);
        $parentVariants = array_map(function ($variant) {
        return [
            'id' => $variant['id'],
            'sku' => $variant['sku']
        ];
    }, $this->variants);

    $this->addParentVariantMetafieldsToVariants($store2Map, $parentVariants, $config);

    // 8. Media (dacă e cazul)
    if (!empty($this->store1Images)) {
        $this->getProductMedia($store2ProductId, $client);
        $this->uploadProductMedia($store2ProductId, $this->store1Images, $client, $matchedProduct['title']);
        sleep(3);
    }

    Log::info("✅ Sincronizare completă pentru produsul de pe Lustreled {$parentProductId}");
}


    private function createShopifyClient(): Client
    {
        return new Client([
            'base_uri' => "https://" . env('STORE_URL_LUSTRELED') . "/admin/api/2024-07/graphql.json",
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Shopify-Access-Token' => env('ACCESS_TOKEN_ADMIN_LUSTRELED')
            ]
        ]);
    }

    // private function findProductInStore2ByHandleAndMetafield(Client $client, string $handle, int $expectedParentProductId): ?array
    // {
    //     $query = <<<'GRAPHQL'
    //     query getProduct($handle: String!) {
    //         productByHandle(handle: $handle) {
    //             id
    //             title
    //             metafield(namespace: "custom", key: "parentproduct") {
    //                 value
    //             }
    //             variants(first: 10) {  # Fetch all variants (you can adjust the 'first' to increase/decrease the limit)
    //                 edges {
    //                     node {
    //                         id
    //                         sku
    //                         title
    //                         price
    //                         compareAtPrice
    //                         inventoryQuantity
    //                         barcode
    //                         taxable
    //                         title
    //                         selectedOptions{
    //                             optionValue{
    //                               name
    //                             }
    //                         }
    //                         metafields(first: 5, namespace: "custom") {
    //                             edges {
    //                             node {
    //                                 key
    //                                 value
    //                             }
    //                             }
    //                         }
    //                     }
    //                 }
    //             }
    //         }
    //     }
    //     GRAPHQL;

    //     try {
    //         $response = $client->post('', [
    //             'json' => [
    //                 'query' => $query,
    //                 'variables' => ['handle' => $handle],
    //             ],
    //         ]);

    //         $body = json_decode($response->getBody(), true);
    //         $product = $body['data']['productByHandle'] ?? null;

    //         if (!$product) {
    //             Log::warning("❌ No product found by handle: {$handle}");
    //             return null;
    //         }

    //         $metafieldValue = $product['metafield']['value'] ?? null;

    //         if ($metafieldValue == $expectedParentProductId) {
    //             // Include variants in the returned product
    //             $variants = $product['variants']['edges'] ?? [];
    //             Log::info("✅ Product found by handle '{$handle}' with " . count($variants) . " variants.");
    //             return [
    //                 'id' => $product['id'],
    //                 'title' => $product['title'],
    //                 'variants' => $variants
    //             ];
    //         }

    //         Log::warning("⚠️ Product found by handle, but metafield mismatch. Expected: {$expectedParentProductId}, Found: {$metafieldValue}");
    //         return null;

    //     } catch (\Exception $e) {
    //         Log::error("❌ Error during handle + metafield lookup for handle '{$handle}': " . $e->getMessage());
    //         return null;
    //     }
    // }

    private function findProductInStore2ByHandleAndMetafield(Client $client, string $handle, int $expectedParentProductId): ?array
    {
        $queryByHandle = <<<'GRAPHQL'
        query getProduct($handle: String!) {
            productByHandle(handle: $handle) {
                id
                title
                handle
                metafield(namespace: "custom", key: "parentproduct") {
                    value
                }
                variants(first: 100) {
                    edges {
                        node {
                            id
                            sku
                            title
                            price
                            compareAtPrice
                            inventoryQuantity
                            barcode
                            taxable
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

        try {
            $response = $client->post('', [
                'json' => [
                    'query' => $queryByHandle,
                    'variables' => ['handle' => $handle],
                ],
            ]);

            $body = json_decode($response->getBody(), true);
            $product = $body['data']['productByHandle'] ?? null;

            if ($product) {
                $metafieldValue = $product['metafield']['value'] ?? null;

                if ($metafieldValue == $expectedParentProductId) {
                    Log::info("✅ Found product by handle '{$handle}' with matching metafield.");
                    return [
                        'id' => $product['id'],
                        'title' => $product['title'],
                        'variants' => $product['variants']['edges'] ?? [],
                    ];
                }

                Log::warning("⚠️ Found product by handle '{$handle}' but metafield mismatch (Expected: {$expectedParentProductId}, Found: {$metafieldValue}).");
            } else {
                Log::warning("❌ No product found by handle: {$handle}");
            }
        } catch (\Exception $e) {
            Log::error("❌ Error during handle lookup: " . $e->getMessage());
        }

        // 🟨 Fallback: Căutare după metafield
        Log::info("🔍 Trying fallback search by metafield 'parentproduct'...");

        $fallbackQuery = <<<'GRAPHQL'
        {
            products(first: 100, query: "metafield:custom.parentproduct") {
                edges {
                    node {
                        id
                        title
                        handle
                        metafield(namespace: "custom", key: "parentproduct") {
                            value
                        }
                        variants(first: 100) {
                            edges {
                                node {
                                    id
                                    sku
                                    title
                                    price
                                    selectedOptions {
                                        name
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

        try {
            $fallbackResponse = $client->post('', [
                'json' => ['query' => $fallbackQuery]
            ]);

            $data = json_decode($fallbackResponse->getBody(), true);
            $products = $data['data']['products']['edges'] ?? [];

            foreach ($products as $edge) {
                $product = $edge['node'];
                $metafield = $product['metafield']['value'] ?? null;

                if ((int)$metafield === $expectedParentProductId) {
                    Log::info("✅ Fallback matched product by parentproduct metafield. Handle: {$product['handle']}");

                    return [
                        'id' => $product['id'],
                        'title' => $product['title'],
                        'variants' => $product['variants']['edges'] ?? [],
                    ];
                }
            }

            Log::warning("❌ No product matched fallback metafield search.");
        } catch (\Exception $e) {
            Log::error("❌ Error during fallback metafield lookup: " . $e->getMessage());
        }

        return null;
    }


    private function findProductInStore2BySku(Client $client, string $sku): ?array
    {
        $query = <<<GRAPHQL
        query getProductByVariantSku(\$sku: String!) {
            productVariants(first: 250, query: \$sku) {  # Fetch all variants (you can adjust the 'first' to increase/decrease the limit)
                edges {
                    node {
                        id
                        sku
                        product {
                            id
                            title
                            handle
                            variants(first: 250) {  # Fetch all variants for the product
                                edges {
                                    node {
                                        id
                                        sku
                                        title
                                        price
                                        compareAtPrice
                                        inventoryQuantity
                                        barcode
                                        taxable
                                        title
                                        selectedOptions{
                                            optionValue{
                                            name
                                            }
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
                }
            }
        }
        GRAPHQL;

        $variables = [
            'sku' => "sku:$sku"
        ];

        try {
            $response = $client->post('', [
                'json' => [
                    'query' => $query,
                    'variables' => $variables
                ]
            ]);

            $body = json_decode($response->getBody(), true);
            $edges = $body['data']['productVariants']['edges'] ?? [];

            if (!empty($edges)) {
                $variant = $edges[0]['node'];
                $product = $variant['product'];

                // Fetch variants for the product
                $variants = $product['variants']['edges'] ?? [];
                Log::info("🔁 Found product in Store 2 by SKU {$sku}, with " . count($variants) . " variants.");
                return [
                    'id' => $product['id'],
                    'title' => $product['title'],
                    'variants' => $variants
                ];
            } else {
                Log::warning("❌ No product found in Store 2 with SKU: {$sku}");
                return null;
            }
        } catch (\Exception $e) {
            Log::error("❌ Error during SKU query for SKU {$sku}: " . $e->getMessage());
            return null;
        }
    }

    private function updateProductInStore2(Client $client, string $productIdStore2): void
    {
        $mutation = <<<'GRAPHQL'
        mutation productUpdate($input: ProductInput!) {
            productUpdate(input: $input) {
                product {
                    id
                    title
                    handle
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;
    
        // Prepare the input for the mutation
        $input = [
            'id' => $productIdStore2,
            'title' => $this->productDetails['title'],
            'bodyHtml' => $this->productDetails['body_html'],
            'productType' => $this->productDetails['product_type'],
            'handle' => $this->productDetails['handle'],
            'status' => strtoupper($this->productDetails['status'] ?? 'DRAFT'),
            'vendor' => $this->productDetails['vendor'],
            'tags' => $this->productDetails['tags']
        ];
    
        try {
            $response = $client->post('', [
                'json' => [
                    'query' => $mutation,
                    'variables' => ['input' => $input]
                ]
            ]);
    
            $body = json_decode($response->getBody(), true);
    
            // Log the full response for debugging purposes
            //Log::info('Full Shopify Response for Product Update:', ['response' => $body]);
    
            // Check for the data key in the response
            if (isset($body['data']['productUpdate'])) {
                $product = $body['data']['productUpdate']['product'];
                //Log::info('✅ Product updated successfully:', $product);
            } else {
                // Log the error response if 'data' is missing
                Log::error('❌ Error: Missing data key in response:', $body);
                if (isset($body['errors'])) {
                    Log::error('❌ Errors in response:', $body['errors']);
                }
            }
        } catch (\Exception $e) {
            Log::error('❌ Exception during product update: ' . $e->getMessage());
        }
    }
    
    private function formatTags($tags)
    {
        // Make sure tags are returned as an array
        return is_array($tags) ? $tags : explode(',', $tags);
    }

    private function setInventoryQuantity($client, $inventoryItemId, $availableQuantity)
    {
        $mutation = <<<'GRAPHQL'
        mutation inventorySetOnHandQuantities($input: InventorySetOnHandQuantitiesInput!) {
            inventorySetOnHandQuantities(input: $input) {
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;
    
        $variables = [
            'input' => [
                'setQuantities' => [
                    [
                        'inventoryItemId' => "gid://shopify/InventoryItem/" . $inventoryItemId,
                        'locationId' => 'gid://shopify/Location/' . env('LOCATION_LUSTRELED'),
                        'quantity' => (int) $availableQuantity,
                    ]
                ],
                'reason' => 'correction',
            ]
        ];
    
        try {
            $response = $client->post('', [
                'json' => [
                    'query' => $mutation,
                    'variables' => $variables,
                ],
            ]);
    
            $body = json_decode($response->getBody(), true);
            Log::info("📦 Inventory updated via setInventoryQuantity:", ['response' => $body]);
    
            if (!empty($body['data']['inventorySetOnHandQuantities']['userErrors'])) {
                Log::error("❌ Inventory update errors:", ['errors' => $body['data']['inventorySetOnHandQuantities']['userErrors']]);
            }
        } catch (\Exception $e) {
            Log::error("❌ Failed to set inventory quantity:", ['message' => $e->getMessage()]);
        }
    }

    public function updateVariantInStore2(Client $client, $variantIdStore2, array $variantDataStore1)
    {
        $mutation = <<<'GRAPHQL'
        mutation productVariantUpdate($input: ProductVariantInput!) {
            productVariantUpdate(input: $input) {
                productVariant {
                    id
                    sku
                    price
                    compareAtPrice
                    barcode
                    taxable
                    title
                    selectedOptions {  
                        name
                        value
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;
    
        // Prepare the input for the mutation
        $input = [
            'id' => $variantIdStore2,
            'price' => $variantDataStore1['price'],
            'compareAtPrice' => $variantDataStore1['compare_at_price'],
            'barcode' => $variantDataStore1['barcode'],
            'inventoryPolicy' => strtoupper($variantDataStore1['inventory_policy']),
            'taxable' => $variantDataStore1['taxable'],
            'title' => $variantDataStore1['title'],
            'inventoryItem' => [
                'sku' => $variantDataStore1['sku']
            ],
            'options' => []  // Initialize options as an empty array
        ];
    
        // Conditionally set options if values exist
        if (!empty($variantDataStore1['option1'])) {
            $input['options'][] = $variantDataStore1['option1'];  // Add the option directly to the array
        }
        if (!empty($variantDataStore1['option2'])) {
            $input['options'][] = $variantDataStore1['option2'];  // Add the option directly to the array
        }
        if (!empty($variantDataStore1['option3'])) {
            $input['options'][] = $variantDataStore1['option3'];  // Add the option directly to the array
        }
    
        // Debugging: Log the input data sent to Shopify
        //Log::info('Debugging Shopify Variant Update Input:', ['input' => $input]);
    
        try {
            $response = $client->post('', [
                'json' => [
                    'query' => $mutation,
                    'variables' => ['input' => $input]
                ]
            ]);
    
            $body = json_decode($response->getBody(), true);
    
            // Log the full response for debugging
            //Log::info('Shopify Variant Update Response:', ['response' => $body]);
    
            // Check if 'data' key exists
            if (isset($body['data']['productVariantUpdate'])) {
                $productVariant = $body['data']['productVariantUpdate']['productVariant'];
                //Log::info('✅ Shopify Variant Updated Successfully:', $productVariant);
                return true;
            } else {
                // Log errors if 'data' key is not found
                Log::error('❌ Shopify Variant Update Errors:', $body['errors']);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Shopify Variant Update Exception: ' . $e->getMessage());
            return false;
        }
    }
    
    public function getProductMedia(string $productId, Client $client)
    {
        $query = <<<'GRAPHQL'
        {
            product(id: "PRODUCT_ID_PLACEHOLDER") {
                media(first: 100) {
                    edges {
                        node {
                            id
                        }
                    }
                }
            }
        }
        GRAPHQL;

        $query = str_replace("PRODUCT_ID_PLACEHOLDER", $productId, $query);

        try {
            $response = $client->post('', [
                'json' => [
                    'query' => $query
                ]
            ]);

            $body = json_decode($response->getBody(), true);

            if (isset($body['errors'])) {
                Log::error("🚨 Failed to retrieve media:", $body);
                return;
            }

            $mediaIds = collect($body['data']['product']['media']['edges'])
                ->map(fn($edge) => $edge['node']['id'])
                ->toArray();

            //Log::info("📸 Retrieved Media IDs for Product $productId:", $mediaIds);

            // 🚀 DELETE MEDIA HERE
            sleep(3);
            $this->deleteProductMedia($productId, $mediaIds, $client);

        } catch (\Exception $e) {
            Log::error("🚨 Shopify API Exception: " . $e->getMessage());
        }
    }

    public function deleteProductMedia(string $productId, array $mediaIds, Client $client)
    {
        if (empty($mediaIds)) {
            //Log::info("✅ No media to delete for product: $productId");
            return;
        }

        $mutation = <<<'GRAPHQL'
        mutation productDeleteMedia($mediaIds: [ID!]!, $productId: ID!) {
            productDeleteMedia(mediaIds: $mediaIds, productId: $productId) {
                deletedMediaIds
                deletedProductImageIds
                mediaUserErrors {
                    field
                    message
                }
                product {
                    id
                    title
                }
            }
        }
        GRAPHQL;

        $variables = [
            'mediaIds' => $mediaIds,
            'productId' => $productId
        ];

        try {
            $response = $client->post('', [
                'json' => [
                    'query' => $mutation,
                    'variables' => $variables
                ]
            ]);

            $body = json_decode($response->getBody(), true);

            if (isset($body['errors']) || !empty($body['data']['productDeleteMedia']['mediaUserErrors'])) {
                Log::error("🚨 Media Deletion Failed:", $body);
                return;
            }

            $deletedMediaIds = $body['data']['productDeleteMedia']['deletedMediaIds'] ?? [];
            //Log::info("✅ Successfully deleted media:", $deletedMediaIds);

        } catch (\Exception $e) {
            Log::error("🚨 Shopify API Exception during media deletion: " . $e->getMessage());
        }

        sleep(1);
    }
    

    public function uploadProductMedia(string $productId, array $imageUrls, Client $client, string $productTitle)
    {
        if (empty($imageUrls)) {
            Log::info("✅ No media to upload for product: $productId");
            return;
        }
    
        // Prepare media input with dynamic alt text
        $mediaArray = array_map(fn($url) => [
            'alt' => "{$productTitle} lustreled.ro", 
            'mediaContentType' => 'IMAGE',
            'originalSource' => $url,
        ], $imageUrls);
    
        // Mutation to upload media to a product
        $mutation = <<<'GRAPHQL'
        mutation productCreateMedia($media: [CreateMediaInput!]!, $productId: ID!) {
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
        }
        GRAPHQL;
    
        $variables = [
            'media' => $mediaArray,
            'productId' => $productId
        ];
    
        try {
            // Send the mutation request
            $response = $client->post('', [
                'json' => [
                    'query' => $mutation,
                    'variables' => $variables
                ]
            ]);
    
            $body = json_decode($response->getBody(), true);
    
            // Check for errors in the response
            if (isset($body['errors']) || !empty($body['data']['productCreateMedia']['mediaUserErrors'])) {
                Log::error("🚨 Media Upload Failed:", $body);
                return;
            }
    
            // Log successful media upload
            //Log::info("✅ Successfully uploaded media:", $body['data']['productCreateMedia']['media']);
        } catch (\Exception $e) {
            // Handle exceptions
            Log::error("🚨 Shopify API Exception: " . $e->getMessage());
        }
    }
    
    private function findProductByVariantSkuFallback(Client $client, string $sku): ?array
    {
        $query = <<<'GRAPHQL'
        query getProductByVariantSku($sku: String!) {
            productVariants(first: 1, query: $sku) {
                edges {
                    node {
                        id
                        sku
                        product {
                            id
                            title
                            handle
                            variants(first: 100) {
                                edges {
                                    node {
                                        id
                                        sku
                                        title
                                        price
                                        compareAtPrice
                                        inventoryQuantity
                                        barcode
                                        taxable
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
                }
            }
        }
        GRAPHQL;
    
        $variables = [
            'sku' => "sku:$sku" // Shopify requires `sku:` prefix for searching
        ];
    
        try {
            $response = $client->post('', [
                'json' => [
                    'query' => $query,
                    'variables' => $variables
                ]
            ]);
    
            $body = json_decode($response->getBody(), true);
            $edges = $body['data']['productVariants']['edges'] ?? [];
    
            if (!empty($edges)) {
                $variant = $edges[0]['node'];
                $product = $variant['product'];
                $variants = $product['variants']['edges'] ?? [];
    
                Log::info("🔁 Fallback: Found product by variant SKU '{$sku}' with " . count($variants) . " variants.");
    
                return [
                    'id' => $product['id'],
                    'title' => $product['title'],
                    'variants' => $variants,
                ];
            } else {
                Log::warning("❌ Fallback: No product variant found with SKU '{$sku}'.");
                return null;
            }
        } catch (\Exception $e) {
            Log::error("❌ Exception in fallback variant SKU search for '{$sku}': " . $e->getMessage());
            return null;
        }
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
                //Log::info("📝 Metafield 'parentproduct' set successfully on product.");
            } else {
                Log::warning("⚠️ Failed to set 'parentproduct' metafield:", $body['data']['metafieldsSet']['userErrors']);
            }
        } catch (\Exception $e) {
            Log::error("❌ Error setting 'parentproduct' metafield: " . $e->getMessage());
        }
    }

    
    private function addParentVariantMetafieldsToVariants(array $store2Map, array $parentVariants, array $config)
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

        foreach ($store2Map as $store1Id => $store2GID) {
            $matchingParent = collect($parentVariants)->firstWhere('id', $store1Id);

            if (!$matchingParent) {
                Log::warning("⚠️ No matching parent variant for Store 1 variant ID: {$store1Id}");
                continue;
            }

            $variables = [
                'metafields' => [[
                    'ownerId' => $store2GID,
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
                    //Log::info("🔗 Metafield 'parentvariant' set for Store 2 Variant ID: {$store2GID}");
                } else {
                    Log::warning("⚠️ Failed to set metafield for variant ID {$store2GID}", $body['data']['metafieldsSet']['userErrors']);
                }
            } catch (\Exception $e) {
                Log::error("❌ Error setting parentvariant metafield for variant ID {$store2GID}: " . $e->getMessage());
            }
        }
    }


    private function createVariantInStore2(Client $client, string $productId, array $variant): array
    {
        $mutation = <<<'GRAPHQL'
        mutation productVariantCreate($input: ProductVariantInput!) {
            productVariantCreate(input: $input) {
                productVariant {
                    id
                    sku
                    title
                    price
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $input = [
            'productId' => $productId,
            'price' => $variant['price'] ?? null,
            'title' => $variant['title'] ?? null,
            'inventoryItem' => [
                'sku' => $variant['sku'] ?? null,
            ],
            'options' => array_filter([
                $variant['option1'] ?? null,
                $variant['option2'] ?? null,
                $variant['option3'] ?? null,
            ]),
        ];

        try {
            $response = $client->post('', [
                'json' => [
                    'query' => $mutation,
                    'variables' => ['input' => $input],
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            // ✅ Dacă a fost creat cu succes
            if (!empty($body['data']['productVariantCreate']['productVariant']['id'])) {
                $createdVariant = $body['data']['productVariantCreate']['productVariant'];
                $createdId = $createdVariant['id'];

                //Log::info("✅ Variant created via GraphQL: {$createdId} for SKU: {$variant['sku']}");

                $this->setParentVariantMetafield($client, $createdId, $variant['id']);

                return [
                    'success' => true,
                    'new_variant' => array_merge($createdVariant, [
                        'option1' => $variant['option1'] ?? null,
                        'option2' => $variant['option2'] ?? null,
                        'option3' => $variant['option3'] ?? null,
                        'metafields' => [
                            'edges' => [
                                ['node' => ['key' => 'parentvariant', 'value' => (string) $variant['id']]]
                            ]
                        ],
                    ])
                ];
            }

            // ⚠️ Dacă eroarea este „already exists”, fallback
            $errors = $body['data']['productVariantCreate']['userErrors'] ?? [];
            foreach ($errors as $error) {
                if (str_contains($error['message'] ?? '', 'already exists')) {
                    Log::warning("⚠️ Variant already exists. Trying fallback search...");

                    $existingVariant = $this->findVariantInStore2ByOptions($client, $productId, $variant);

                    if ($existingVariant) {
                        // Setăm manual parentvariant
                        $this->setParentVariantMetafield($client, $existingVariant['id'], $variant['id']);

                        return [
                            'success' => true,
                            'new_variant' => array_merge($existingVariant, [
                                'option1' => $variant['option1'] ?? null,
                                'option2' => $variant['option2'] ?? null,
                                'option3' => $variant['option3'] ?? null,
                                'metafields' => [
                                    'edges' => [
                                        ['node' => ['key' => 'parentvariant', 'value' => (string) $variant['id']]]
                                    ]
                                ],
                            ])
                        ];
                    }
                }
            }

            Log::warning("⚠️ GraphQL Variant creation errors:", $errors);
        } catch (\Exception $e) {
            Log::error("❌ GraphQL error during variant creation: " . $e->getMessage());
        }

        return [
            'success' => false,
            'new_variant' => null,
        ];
    }



    private function setParentVariantMetafield(Client $client, $store2VariantId, $parentVariantId)
    {
        $mutation = <<<GRAPHQL
        mutation metafieldsSet(\$metafields: [MetafieldsSetInput!]!) {
            metafieldsSet(metafields: \$metafields) {
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

        $variables = [
            'metafields' => [[
                'ownerId' =>  $store2VariantId,
                'namespace' => 'custom',
                'key' => 'parentvariant',
                'type' => 'number_integer',
                'value' => (string) $parentVariantId,
            ]]
        ];

        try {
            $response = $client->post('', [
                'json' => [
                    'query' => $mutation,
                    'variables' => $variables,
                ]
            ]);

            $body = json_decode($response->getBody(), true);

            if (!empty($body['data']['metafieldsSet']['metafields'])) {
               // Log::info("🔗 Metafield 'parentvariant' set for Variant ID: {$store2VariantId}");
            } else {
                Log::warning("⚠️ Metafield error for Variant ID {$store2VariantId}", $body['data']['metafieldsSet']['userErrors'] ?? []);
            }
        } catch (\Exception $e) {
            Log::error("❌ GraphQL error for setting metafield: " . $e->getMessage());
        }
    }

    private function syncVariants(Client $client, string $productIdStore2, array $store2VariantsEdges): void
    {
        // 1. Normalizează Store 2 variants din edges
        $store2Variants = collect($store2VariantsEdges)->map(function ($edge) {
            $variant = $edge['node'];
            $options = collect($variant['selectedOptions'])->pluck('value')->all();

            return [
                'id' => $variant['id'],
                'sku' => $variant['sku'],
                'title' => $variant['title'],
                'option1' => $options[0] ?? null,
                'option2' => $options[1] ?? null,
                'option3' => $options[2] ?? null,
                'metafields' => $variant['metafields'] ?? [],
            ];
        })->toArray();

        // 2. Compară diferențele
        $result = $this->mapVariantDifferences($this->variants, $store2Variants);

        // 🔁 UPDATE
        foreach ($result['toUpdate'] as $pair) {
            Log::info("🔁 Updating variant in Store 2", [
                'store2_id' => $pair['store2']['id'],
                'store1_id' => $pair['store1']['id']
            ]);
            $this->updateVariantInStore2($client, $pair['store2']['id'], $pair['store1']);
            sleep(1);
        }

        // ➕ CREATE
        foreach ($result['toCreate'] as $variant) {
            Log::info("➕ Creating variant in Store 2", [
                'store1_id' => $variant['id'],
                'title' => $variant['title']
            ]);
            
            $createResult = $this->createVariantInStore2($client, $productIdStore2, $variant);
            sleep(1);

            if ($createResult['success']) {
                // Adăugăm la lista cu variante sincronizate și mapăm corect
                $store2Variants[] = $createResult['new_variant'];
 
                // Adăugăm explicit în maparea cu parentvariant corect
                $this->setParentVariantMetafield($client, $createResult['new_variant']['id'], $variant['id']);
            }
        }

        // ♻️ REINDEXĂM variantele din Store 2 după ce am creat cele lipsă
        $result = $this->mapVariantDifferences($this->variants, $store2Variants);

        // 🗑️ DELETE
        foreach ($result['toDelete'] as $variant) {
            Log::info("🗑️ Deleting variant from Store 2", [
                'store2_id' => $variant['id'],
                'title' => $variant['title']
            ]);
            $this->deleteVariantFromStore2($client, $variant['id']);
            sleep(1);
        }
    }


    // private function mapVariantDifferences(array $store1Variants, array $store2Variants)
    // {
    //     $store1ById = collect($store1Variants)->keyBy('id');
    //     $store1KeysByOptions = collect($store1Variants)->mapWithKeys(function ($v) {
    //         $key = implode('|', [$v['option1'], $v['option2'], $v['option3']]);
    //         return [$key => $v];
    //     });

    //     $store2ByParentId = [];
    //     $store2KeysByOptions = [];

    //     foreach ($store2Variants as $variant) {
    //         $parentMeta = collect($variant['metafields']['edges'] ?? [])
    //             ->firstWhere('node.key', 'parentvariant');

    //         if ($parentMeta) {
    //             $parentId = (int)$parentMeta['node']['value'];
    //             $store2ByParentId[$parentId] = $variant;
    //         }

    //         $key = implode('|', [$variant['option1'], $variant['option2'], $variant['option3']]);
    //         $store2KeysByOptions[$key] = $variant;
    //     }

    //     $toUpdate = [];
    //     $matchedParentIds = [];
    //     $matchedOptionKeys = [];

    //     foreach ($store1ById as $id => $v) {
    //         $key = implode('|', [$v['option1'], $v['option2'], $v['option3']]);

    //         if (isset($store2ByParentId[$id])) {
    //             $toUpdate[] = ['store1' => $v, 'store2' => $store2ByParentId[$id]];
    //             $matchedParentIds[] = $id;
    //             $matchedOptionKeys[] = $key;
    //             \Log::debug("🔁 Matched by parentvariant ID: $id => $key");
    //         } elseif (isset($store2KeysByOptions[$key])) {
    //             $toUpdate[] = ['store1' => $v, 'store2' => $store2KeysByOptions[$key]];
    //             $matchedOptionKeys[] = $key;
    //             \Log::debug("🔁 Fallback match by options: $key");
    //         } else {
    //             \Log::debug("❌ No match for variant: ID=$id, Key=$key");
    //         }
    //     }

    //     $toCreate = collect($store1ById)
    //         ->reject(function ($v) use ($matchedParentIds, $matchedOptionKeys) {
    //             $key = implode('|', [$v['option1'], $v['option2'], $v['option3']]);
    //             return in_array($v['id'], $matchedParentIds) || in_array($key, $matchedOptionKeys);
    //         })
    //         ->values()
    //         ->all();

    //     if (!empty($toCreate)) {
    //         \Log::debug("🆕 Variants marked for creation:", collect($toCreate)->pluck('title', 'id')->toArray());
    //     }

    //     $parentIdsStore1 = collect($store1Variants)->pluck('id')->map(fn($id) => (string) $id)->toArray();
    //     $toDelete = collect($store2ByParentId)
    //         ->reject(fn($v, $parentId) => in_array($parentId, $parentIdsStore1))
    //         ->values()
    //         ->all();

    //     if (!empty($toDelete)) {
    //         \Log::debug("🗑️ Variants marked for deletion:", collect($toDelete)->pluck('title', 'id')->toArray());
    //     }

    //     return compact('toUpdate', 'toCreate', 'toDelete');
    // }

    private function mapVariantDifferences(array $store1Variants, array $store2Variants)
    {
        $store1ById = collect($store1Variants)->keyBy('id');

        $store2ByParentId = [];
        $store2KeysByOptions = [];

        foreach ($store2Variants as $variant) {
            $parentMeta = collect($variant['metafields']['edges'] ?? [])
                ->firstWhere('node.key', 'parentvariant');

            if ($parentMeta) {
                $parentId = (int)$parentMeta['node']['value'];
                $store2ByParentId[$parentId] = $variant;
            }

            $key = implode('|', [$variant['option1'], $variant['option2'], $variant['option3']]);
            $store2KeysByOptions[$key] = $variant;
        }

        $toUpdate = [];
        $matchedStore2Ids = [];

        foreach ($store1ById as $id => $v) {
            $key = implode('|', [$v['option1'], $v['option2'], $v['option3']]);

            if (isset($store2ByParentId[$id])) {
                $store2Variant = $store2ByParentId[$id];
                $toUpdate[] = ['store1' => $v, 'store2' => $store2Variant];
                $matchedStore2Ids[] = $store2Variant['id'];
                \Log::debug("🔁 Matched by parentvariant ID: $id");
            } elseif (isset($store2KeysByOptions[$key])) {
                $store2Variant = $store2KeysByOptions[$key];
                $toUpdate[] = ['store1' => $v, 'store2' => $store2Variant];
                $matchedStore2Ids[] = $store2Variant['id'];
                \Log::debug("🔁 Fallback match by options: $key");
            } else {
                \Log::debug("❌ No match for variant: ID=$id, Key=$key");
            }
        }

        $matchedParentIds = array_column($toUpdate, 'store1.id');

        $toCreate = collect($store1Variants)
            ->reject(fn($v) => in_array($v['id'], $matchedParentIds))
            ->values()
            ->all();

        if (!empty($toCreate)) {
            \Log::debug("🆕 Variants marked for creation:", collect($toCreate)->pluck('title', 'id')->toArray());
        }

        $toDelete = collect($store2Variants)
            ->reject(fn($v) => in_array($v['id'], $matchedStore2Ids))
            ->values()
            ->all();

        if (!empty($toDelete)) {
            \Log::debug("🗑️ Variants marked for deletion:", collect($toDelete)->pluck('title', 'id')->toArray());
        }

        return compact('toUpdate', 'toCreate', 'toDelete');
    }



    private function deleteVariantFromStore2(Client $client, string $variantId)
    {
        $mutation = <<<'GRAPHQL'
        mutation productVariantDelete($id: ID!) {
            productVariantDelete(id: $id) {
                deletedProductVariantId
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        try {
            $response = $client->post('', [
                'json' => [
                    'query' => $mutation,
                    'variables' => ['id' => $variantId]
                ]
            ]);

            $body = json_decode($response->getBody(), true);

            if (!empty($body['data']['productVariantDelete']['deletedProductVariantId'])) {
                //Log::info("🗑️ Variant deleted successfully: {$variantId}");
            } else {
                Log::warning("⚠️ Failed to delete variant {$variantId}", $body['data']['productVariantDelete']['userErrors'] ?? []);
            }
        } catch (\Exception $e) {
            Log::error("❌ Exception while deleting variant {$variantId}: " . $e->getMessage());
        }
    }

    private function findVariantInStore2ByOptions(Client $client, string $productId, array $variant): ?array
    {
        $query = <<<'GRAPHQL'
        query getProductVariants($id: ID!) {
            product(id: $id) {
                variants(first: 100) {
                    edges {
                        node {
                            id
                            sku
                            title
                            price
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

        try {
            $response = $client->post('', [
                'json' => [
                    'query' => $query,
                    'variables' => ['id' => $productId],
                ]
            ]);

            $body = json_decode($response->getBody(), true);
            $variants = $body['data']['product']['variants']['edges'] ?? [];

            foreach ($variants as $edge) {
                $v = $edge['node'];
                $values = collect($v['selectedOptions'])->pluck('value')->all();

                if (
                    ($values[0] ?? null) === ($variant['option1'] ?? null) &&
                    ($values[1] ?? null) === ($variant['option2'] ?? null) &&
                    ($values[2] ?? null) === ($variant['option3'] ?? null)
                ) {
                    return $v;
                }
            }

            Log::warning("❌ Fallback variant not found by options in Store 2");
        } catch (\Exception $e) {
            Log::error("❌ Error searching variant by options: " . $e->getMessage());
        }

        return null;
    }



}
