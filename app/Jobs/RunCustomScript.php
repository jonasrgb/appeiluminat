<?php

namespace App\Jobs;

use GuzzleHttp\Client;
use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use App\Mail\JobFailedMail;


class RunCustomScript implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $collectionId;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->collectionId = "622468399449";  // ID-ul colecției
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            // Creează clientul Guzzle
            $client = new Client([
                'base_uri' => 'https://6931e0469da2f74cc04dc0e57313c4e6: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@lustreled.myshopify.com/admin/api/2025-01/',
                'http_errors' => false,
            ]);

            // Fetch products from collection
            $productsData = $this->fetchProductsFromCollection($this->collectionId, $client);

            if (empty($productsData['products'])) {
                \Log::info('No products found in the collection');
                return;
            }

            $currentTime = new DateTime('now');

            // Preluarea produselor și verificarea celor mai vechi
            $formattedProducts = array_map(function ($product) use ($currentTime) {
                return $this->extractProductData($product, $currentTime);
            }, $productsData['products']);

            $olderProductIds = array_column(array_filter($formattedProducts, function ($product) {
                return $product['older'];
            }), 'id');

            \Log::info('Older product IDs: ' . json_encode($olderProductIds));

            // Îndepărtează produsele mai vechi de 60 de zile
            if (!empty($olderProductIds)) {
                $this->removeProductsFromCollection($olderProductIds, $this->collectionId, $client);
            }

            \Log::info('Products removal job completed successfully lustreled');
        } catch (\Exception $e) {
            \Log::error('Error during product removal: ' . $e->getMessage());
            // Trimite email în caz de eroare
            Mail::to('mitnickoff121@gmail.com')->send(new JobFailedMail($e->getMessage()));
        }
    }

    /**
     * Metodă pentru a prelua produsele din colecție
     */
    private function fetchProductsFromCollection($collectionId, $client, $pageInfo = null)
    {
        $products = [];
        do {
            $endpoint = "collections/{$collectionId}/products.json";
            $params = [
                'query' => [
                    'limit' => 50,
                    'page_info' => $pageInfo,
                ],
            ];

            $response = $client->request('GET', $endpoint, $params);
            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody(), true);

            if ($statusCode >= 400) {
                throw new \Exception('Shopify API returned error: ' . $statusCode);
            }

            $products = array_merge($products, $body['products']);
            $linkHeader = $response->getHeader('Link');
            $pageInfo = $this->getNextPageInfo($linkHeader);
        } while ($pageInfo);

        return ['products' => $products];
    }

    /**
     * Metodă pentru a îndepărta produsele din colecție
     */
    private function removeProductsFromCollection(array $productIds, $collectionId, $client)
    {
        try {
            $productGids = array_map(function ($productId) {
                return 'gid://shopify/Product/' . $productId;
            }, $productIds);

            $query = <<<GRAPHQL
            mutation CollectionRemoveProducts(\$id: ID!, \$productIds: [ID!]!) {
                collectionRemoveProducts(id: \$id, productIds: \$productIds) {
                    job {
                        done
                        id
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
            GRAPHQL;

            $variables = [
                'id' => 'gid://shopify/Collection/' . $collectionId,
                'productIds' => array_values($productGids),
            ];

            $response = $client->request('POST', 'graphql.json', [
                'json' => ['query' => $query, 'variables' => $variables]
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody(), true);

            if ($statusCode >= 400) {
                throw new \Exception('Shopify GraphQL API returned error: ' . $statusCode);
            }

            if (!empty($body['errors'])) {
                $errorMessages = array_map(function ($error) {
                    return $error['message'];
                }, $body['errors']);
                throw new \Exception('GraphQL errors: ' . implode(', ', $errorMessages));
            }

            \Log::info('Products removed successfully from collection lustreled');
        } catch (\Exception $e) {
            \Log::error('Failed to remove products from collection: ' . $e->getMessage());
        }
    }

    /**
     * Extrage datele produsului și verifică dacă este mai vechi de 60 de zile
     */
    private function extractProductData($product, $currentTime)
    {
        $createdAt = new DateTime($product['created_at']);
        $diff = $createdAt->diff($currentTime);
        $isOlderThanSixtyDays = $diff->days >= 60;

        return [
            'id' => $product['id'],
            'title' => $product['title'],
            'created_at' => $product['created_at'],
            'status' => $product['status'],
            'older' => $isOlderThanSixtyDays,
        ];
    }

    /**
     * Extrage informațiile pentru pagina următoare
     */
    private function getNextPageInfo($linkHeader)
    {
        if (empty($linkHeader)) {
            return null;
        }

        foreach ($linkHeader as $link) {
            if (preg_match('/<([^>]+)>;\s*rel="next"/', $link, $matches)) {
                $nextPageInfo = parse_url($matches[1], PHP_URL_QUERY);
                parse_str($nextPageInfo, $query);
                return $query['page_info'] ?? null;
            }
        }

        return null;
    }
}
