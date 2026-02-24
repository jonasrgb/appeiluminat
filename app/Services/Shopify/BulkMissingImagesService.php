<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BulkMissingImagesService
{
    private const RUN_MUTATION = <<<'GQL'
    mutation RunBulkMissingImages($query: String!) {
      bulkOperationRunQuery(query: $query) {
        bulkOperation { id status }
        userErrors { field message }
      }
    }
    GQL;

    private const CURRENT_QUERY = <<<'GQL'
    query CurrentBulkOperation {
      currentBulkOperation(type: QUERY) {
        id
        status
        errorCode
        createdAt
        completedAt
        objectCount
        fileSize
        url
        partialDataUrl
      }
    }
    GQL;

    private const BULK_QUERY = <<<'GQL'
    {
      products {
        edges {
          node {
            id
            title
            handle
            mediaCount {
              count
            }
          }
        }
      }
    }
    GQL;

    public function runForShop(
        Shop $shop,
        int $timeoutSeconds = 900,
        int $pollSeconds = 5,
        int $sampleLimit = 20
    ): array {
        $client = $this->makeClient($shop);

        $runResult = $this->startBulkOperation($client, $shop);
        $operationId = $runResult['id'] ?? null;

        if (!$operationId) {
            throw new RuntimeException('Shopify did not return a bulk operation id.');
        }

        $finalState = $this->waitForCompletion($client, $operationId, $timeoutSeconds, $pollSeconds);

        if (($finalState['status'] ?? null) !== 'COMPLETED') {
            $code = $finalState['errorCode'] ?? 'unknown';
            throw new RuntimeException("Bulk operation ended with status {$finalState['status']} (errorCode: {$code}).");
        }

        $url = $finalState['url'] ?? null;
        $partialUrl = $finalState['partialDataUrl'] ?? null;
        if (!$url && !$partialUrl) {
            throw new RuntimeException('Bulk operation completed without result URL.');
        }

        $rawResult = $this->downloadBulkResult($url, $partialUrl);
        $savedPath = $this->storeRawResult($shop, $operationId, $rawResult);

        $parsed = $this->parseProductsJsonl($rawResult, $sampleLimit, $shop->domain);

        return [
            'shop_id' => $shop->id,
            'shop_domain' => $shop->domain,
            'operation_id' => $operationId,
            'status' => $finalState['status'] ?? null,
            'object_count' => $finalState['objectCount'] ?? null,
            'file_size' => $finalState['fileSize'] ?? null,
            'products_without_images_count' => $parsed['count'],
            'sample' => $parsed['sample'],
            'result_path' => $savedPath,
            'result_url' => $url ?? $partialUrl,
        ];
    }

    private function makeClient(Shop $shop): Client
    {
        $version = $shop->api_version ?: '2025-01';

        return new Client([
            'base_uri' => "https://{$shop->domain}/admin/api/{$version}/",
            'http_errors' => false,
            'timeout' => 90,
            'headers' => [
                'X-Shopify-Access-Token' => $shop->access_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    private function startBulkOperation(Client $client, Shop $shop): array
    {
        $response = $client->post('graphql.json', [
            'json' => [
                'query' => self::RUN_MUTATION,
                'variables' => [
                    'query' => self::BULK_QUERY,
                ],
            ],
        ]);

        $payload = json_decode((string) $response->getBody(), true);

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('Shopify GraphQL HTTP error on start: ' . $response->getStatusCode());
        }

        if (!empty($payload['errors'])) {
            throw new RuntimeException('Shopify GraphQL errors on start: ' . json_encode($payload['errors']));
        }

        $userErrors = $payload['data']['bulkOperationRunQuery']['userErrors'] ?? [];
        if (!empty($userErrors)) {
            throw new RuntimeException('Shopify bulkOperationRunQuery userErrors: ' . json_encode($userErrors));
        }

        $bulk = $payload['data']['bulkOperationRunQuery']['bulkOperation'] ?? null;
        if (!$bulk) {
            throw new RuntimeException('bulkOperationRunQuery returned empty bulkOperation payload.');
        }

        return $bulk;
    }

    private function waitForCompletion(Client $client, string $operationId, int $timeoutSeconds, int $pollSeconds): array
    {
        $startedAt = time();

        while ((time() - $startedAt) < $timeoutSeconds) {
            $state = $this->fetchCurrentBulkOperation($client);
            if ($state === null) {
                sleep(max(1, $pollSeconds));
                continue;
            }

            if (!empty($state['id']) && $state['id'] !== $operationId) {
                throw new RuntimeException('Current bulk operation id changed unexpectedly.');
            }

            $status = $state['status'] ?? null;
            if (in_array($status, ['COMPLETED', 'FAILED', 'CANCELED', 'EXPIRED'], true)) {
                return $state;
            }

            sleep(max(1, $pollSeconds));
        }

        throw new RuntimeException('Timeout while waiting for bulk operation completion.');
    }

    private function fetchCurrentBulkOperation(Client $client): ?array
    {
        $response = $client->post('graphql.json', [
            'json' => [
                'query' => self::CURRENT_QUERY,
            ],
        ]);

        $payload = json_decode((string) $response->getBody(), true);

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('Shopify GraphQL HTTP error on polling: ' . $response->getStatusCode());
        }

        if (!empty($payload['errors'])) {
            throw new RuntimeException('Shopify GraphQL errors on polling: ' . json_encode($payload['errors']));
        }

        return $payload['data']['currentBulkOperation'] ?? null;
    }

    private function downloadBulkResult(?string $url, ?string $partialUrl = null): string
    {
        $downloadClient = new Client([
            'http_errors' => false,
            'timeout' => 90,
        ]);

        $candidateUrls = array_values(array_unique(array_filter([$url, $partialUrl])));
        $retryableStatuses = [403, 404, 423, 429, 500, 502, 503, 504];

        $lastStatus = null;
        $lastBody = null;

        foreach ($candidateUrls as $candidateUrl) {
            for ($attempt = 1; $attempt <= 8; $attempt++) {
                $response = $downloadClient->get($candidateUrl, [
                    'headers' => [
                        'Accept' => 'application/jsonl,text/plain,*/*',
                    ],
                ]);

                $status = $response->getStatusCode();
                $body = (string) $response->getBody();

                if ($status < 400) {
                    return $body;
                }

                $lastStatus = $status;
                $lastBody = $body;

                if (!in_array($status, $retryableStatuses, true)) {
                    break;
                }

                sleep(2);
            }
        }

        $bodyPreview = $lastBody ? substr($lastBody, 0, 500) : '';
        throw new RuntimeException(
            'Failed downloading bulk result. HTTP ' . ($lastStatus ?? 'unknown') . ($bodyPreview !== '' ? ' body: ' . $bodyPreview : '')
        );
    }

    private function storeRawResult(Shop $shop, string $operationId, string $jsonl): string
    {
        $safeOperationId = preg_replace('/[^A-Za-z0-9_-]/', '_', $operationId);
        $path = "shopify/bulk-missing-images/shop_{$shop->id}/{$safeOperationId}.jsonl";

        Storage::disk('local')->put($path, $jsonl);

        return $path;
    }

    private function parseProductsJsonl(string $jsonl, int $sampleLimit, string $shopDomain): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $jsonl) ?: [];

        $count = 0;
        $sample = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }

            if (!isset($decoded['id'], $decoded['title'])) {
                continue;
            }

            $hasMediaCount = is_array($decoded['mediaCount'] ?? null) && isset($decoded['mediaCount']['count']);
            $mediaCount = $hasMediaCount ? (int) $decoded['mediaCount']['count'] : null;
            if ($hasMediaCount && $mediaCount !== 0) {
                continue;
            }

            $count++;

            if (count($sample) < $sampleLimit) {
                $link = $this->buildAdminProductUrl($shopDomain, (string) $decoded['id']);
                $sample[] = [
                    'title' => (string) $decoded['title'],
                    'link' => $link,
                ];
            }
        }

        return [
            'count' => $count,
            'sample' => $sample,
        ];
    }

    private function buildAdminProductUrl(string $shopDomain, string $productGid): string
    {
        $storeHandle = $this->extractStoreHandle($shopDomain);
        $productId = $this->numericIdFromGid($productGid);

        if (!$productId) {
            return '';
        }

        return "https://admin.shopify.com/store/{$storeHandle}/products/{$productId}";
    }

    private function extractStoreHandle(string $shopDomain): string
    {
        if (str_ends_with($shopDomain, '.myshopify.com')) {
            return substr($shopDomain, 0, -strlen('.myshopify.com'));
        }

        $parts = explode('.', $shopDomain);
        return $parts[0] ?? $shopDomain;
    }

    private function numericIdFromGid(string $gid): ?string
    {
        $pos = strrpos($gid, '/');
        if ($pos === false) {
            return null;
        }

        $id = substr($gid, $pos + 1);
        return $id !== '' ? $id : null;
    }
}
