<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

final class CatalogAuditBulkService
{
    private const MAX_RETRY_ATTEMPTS = 3;

    private const BULK_OPERATION_STATUSES = [
        'CREATED',
        'RUNNING',
        'COMPLETED',
        'FAILED',
        'CANCELED',
        'EXPIRED',
    ];

    private const RUN_MUTATION = <<<'GQL'
    mutation RunCatalogAuditBulkOperation($query: String!) {
      bulkOperationRunQuery(query: $query) {
        bulkOperation { id status }
        userErrors { field message }
      }
    }
    GQL;

    private const CURRENT_QUERY = <<<'GQL'
    query CurrentCatalogAuditBulkOperation {
      currentBulkOperation(type: QUERY) {
        id
        status
        errorCode
        url
        partialDataUrl
      }
    }
    GQL;

    private const BULK_QUERY = <<<'GQL'
    {
      products(query: "status:active") {
        edges {
          node {
            id
            legacyResourceId
            title
            handle
            status
            images(first: 1) { edges { node { id } } }
            variants { edges { node { id legacyResourceId title sku } } }
          }
        }
      }
    }
    GQL;

    /**
     * Task 5 serializes one bulk operation per shop with WithoutOverlapping.
     * Do not add cache locks here: the supported file and database cache drivers are not reliable for this use.
     */
    public function __construct(
        private readonly ?Closure $sleeper = null,
        private readonly ?Closure $clock = null,
    ) {}

    public function downloadSnapshot(Shop $shop, int $timeoutSeconds, int $pollSeconds): string
    {
        $operation = $this->startBulkOperation($shop);
        $operationId = $operation['id'] ?? null;

        if (! $operationId) {
            throw new RuntimeException('Shopify did not return a bulk operation id.');
        }

        $finalState = $this->waitForCompletion($shop, $operationId, $timeoutSeconds, $pollSeconds);
        $status = $finalState['status'] ?? null;

        if ($status !== 'COMPLETED') {
            $errorCode = $finalState['errorCode'] ?? 'unknown';
            throw new RuntimeException("Bulk operation ended with status {$status} (errorCode: {$errorCode}).");
        }

        $resultUrl = $finalState['url'] ?? $finalState['partialDataUrl'] ?? null;
        if (! $resultUrl) {
            throw new RuntimeException('Bulk operation completed without result URL.');
        }

        $response = $this->requestWithRetries(
            fn (): Response => Http::accept('application/jsonl,text/plain,*/*')
                ->timeout(90)
                ->get($resultUrl),
            'bulk result download',
        );

        if ($response->failed()) {
            throw new RuntimeException('Shopify bulk result download failed: HTTP '.$response->status());
        }

        return $response->body();
    }

    private function startBulkOperation(Shop $shop): array
    {
        $payload = $this->graphql($shop, self::RUN_MUTATION, ['query' => self::BULK_QUERY], 'start');
        $userErrors = $payload['data']['bulkOperationRunQuery']['userErrors'] ?? [];

        if ($userErrors !== []) {
            throw new RuntimeException('Shopify bulkOperationRunQuery userErrors: '.json_encode($userErrors));
        }

        $operation = $payload['data']['bulkOperationRunQuery']['bulkOperation'] ?? null;
        if (! is_array($operation)) {
            throw new RuntimeException('bulkOperationRunQuery returned empty bulkOperation payload.');
        }

        return $this->validateStartOperation($operation);
    }

    private function waitForCompletion(Shop $shop, string $operationId, int $timeoutSeconds, int $pollSeconds): array
    {
        $deadline = $this->now() + max(0, $timeoutSeconds);

        while ($this->now() < $deadline) {
            $state = $this->currentBulkOperation($shop, $deadline);

            if ($this->now() >= $deadline) {
                $this->throwCompletionTimeout();
            }

            if ($state['id'] !== $operationId) {
                throw new RuntimeException('Current bulk operation id changed unexpectedly.');
            }

            if (in_array($state['status'], ['COMPLETED', 'FAILED', 'CANCELED', 'EXPIRED'], true)) {
                return $state;
            }

            $remaining = $deadline - $this->now();
            if ($remaining > 0) {
                $interval = $pollSeconds > 0 ? $pollSeconds : 0.1;
                $this->sleepFor(min($interval, $remaining));
            }
        }

        $this->throwCompletionTimeout();
    }

    private function currentBulkOperation(Shop $shop, float $deadline): array
    {
        $payload = $this->graphql($shop, self::CURRENT_QUERY, [], 'polling', $deadline);

        return $this->validatePollOperation($payload['data']['currentBulkOperation']);
    }

    private function graphql(Shop $shop, string $query, array $variables, string $phase, ?float $deadline = null): array
    {
        $headers = [
            'X-Shopify-Access-Token' => $shop->access_token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($phase === 'start') {
            $response = $this->startRequestWithRetries(
                fn (): Response => Http::withHeaders($headers)->timeout(90)->post($this->endpoint($shop), [
                    'query' => $query,
                    'variables' => (object) $variables,
                ]),
            );
        } else {
            if ($deadline === null) {
                throw new RuntimeException('Polling requests require a completion deadline.');
            }

            $response = $this->requestWithRetries(
                fn (int $timeout): Response => Http::withHeaders($headers)->timeout($timeout)->post($this->endpoint($shop), [
                    'query' => $query,
                    'variables' => (object) $variables,
                ]),
                'GraphQL '.$phase,
                $deadline,
            );
        }

        $payload = $this->validateGraphqlResponse($response, $phase);

        return $payload;
    }

    private function validateStartOperation(array $operation): array
    {
        $this->validateOperationId($operation, 'start');
        $this->validateOperationStatus($operation, 'start');

        return $operation;
    }

    private function validatePollOperation(array $operation): array
    {
        $this->validateOperationId($operation, 'polling');
        $this->validateOperationStatus($operation, 'polling');

        foreach (['url', 'partialDataUrl', 'errorCode'] as $field) {
            if (! array_key_exists($field, $operation) || ! is_null($operation[$field]) && ! is_string($operation[$field])) {
                $this->throwResponseShapeError('polling', "{$field} must be null or a string.");
            }
        }

        return $operation;
    }

    private function validateOperationId(array $operation, string $phase): void
    {
        $id = $operation['id'] ?? null;

        if (! is_string($id) || preg_match('#^gid://shopify/BulkOperation/[1-9][0-9]*$#', $id) !== 1) {
            $this->throwResponseShapeError($phase, 'bulkOperation.id must be a valid BulkOperation GID.');
        }
    }

    private function validateOperationStatus(array $operation, string $phase): void
    {
        $status = $operation['status'] ?? null;

        if (! is_string($status) || ! in_array($status, self::BULK_OPERATION_STATUSES, true)) {
            $this->throwResponseShapeError($phase, 'bulkOperation.status must be a valid status string.');
        }
    }

    private function throwResponseShapeError(string $phase, string $message): never
    {
        throw new RuntimeException('Shopify GraphQL response shape error on '.$phase.': '.$message);
    }

    private function validateGraphqlResponse(Response $response, string $phase): array
    {
        if ($response->failed()) {
            throw new RuntimeException('Shopify GraphQL HTTP error on '.$phase.': '.$response->status());
        }

        try {
            $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Shopify GraphQL response contained invalid JSON on '.$phase.'.', 0, $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Shopify GraphQL response must be a JSON object on '.$phase.'.');
        }

        if (! empty($payload['errors'])) {
            throw new RuntimeException('Shopify GraphQL errors on '.$phase.': '.json_encode($payload['errors']));
        }

        $path = $phase === 'start' ? 'bulkOperationRunQuery' : 'currentBulkOperation';
        if (! isset($payload['data']) || ! is_array($payload['data']) || ! array_key_exists($path, $payload['data']) || ! is_array($payload['data'][$path])) {
            throw new RuntimeException('Shopify GraphQL response missing data.'.$path.' on '.$phase.'.');
        }

        return $payload;
    }

    private function startRequestWithRetries(Closure $request): Response
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRY_ATTEMPTS; $attempt++) {
            try {
                $response = $request();
            } catch (ConnectionException $exception) {
                throw new RuntimeException(
                    'Shopify GraphQL start may have been accepted; refusing to retry after a connection error.',
                    0,
                    $exception,
                );
            }

            if ($response->status() >= 500) {
                throw new RuntimeException(
                    'Shopify GraphQL start may have been accepted; refusing to retry HTTP '.$response->status().'.',
                );
            }

            if ($response->status() !== 429 || $attempt === self::MAX_RETRY_ATTEMPTS) {
                return $response;
            }

            $this->sleepFor($this->retryDelay($response, $attempt));
        }

        throw new RuntimeException('Shopify GraphQL start did not return a response.');
    }

    private function requestWithRetries(Closure $request, string $description, ?float $deadline = null): Response
    {
        $response = null;
        $connectionException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRY_ATTEMPTS; $attempt++) {
            $timeout = $deadline === null ? null : $this->pollRequestTimeout($deadline);

            try {
                $response = $timeout === null ? $request() : $request($timeout);
                $connectionException = null;
            } catch (ConnectionException $exception) {
                $response = null;
                $connectionException = $exception;
            }

            if ($response !== null && ! $this->isRetryableStatus($response->status())) {
                return $response;
            }

            if ($attempt < self::MAX_RETRY_ATTEMPTS) {
                $delay = $this->retryDelay($response, $attempt);

                if ($deadline !== null) {
                    $remaining = $deadline - $this->now();
                    if ($remaining <= 0) {
                        $this->throwCompletionTimeout();
                    }

                    $this->sleepFor(min($delay, $remaining));

                    if ($this->now() >= $deadline) {
                        $this->throwCompletionTimeout();
                    }
                } else {
                    $this->sleepFor($delay);
                }
            }
        }

        if ($connectionException !== null) {
            throw new RuntimeException('Shopify '.$description.' failed after retries due to a connection error.', 0, $connectionException);
        }

        return $response;
    }

    private function pollRequestTimeout(float $deadline): int
    {
        $remaining = $deadline - $this->now();
        if ($remaining <= 0) {
            $this->throwCompletionTimeout();
        }

        return min(90, max(1, (int) floor($remaining)));
    }

    private function isRetryableStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function retryDelay(?Response $response, int $attempt): float
    {
        if ($response?->status() === 429) {
            $retryAfter = trim($response->header('Retry-After'));
            if (ctype_digit($retryAfter)) {
                return (float) $retryAfter;
            }

            $retryAt = strtotime($retryAfter);
            if ($retryAt !== false) {
                return max(0.0, $retryAt - $this->now());
            }
        }

        return (float) (2 ** ($attempt - 1));
    }

    private function now(): float
    {
        return $this->clock ? ($this->clock)() : microtime(true);
    }

    private function sleepFor(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        if ($this->sleeper) {
            ($this->sleeper)($seconds);

            return;
        }

        usleep((int) round($seconds * 1_000_000));
    }

    private function throwCompletionTimeout(): never
    {
        throw new RuntimeException('Timeout while waiting for bulk operation completion.');
    }

    private function endpoint(Shop $shop): string
    {
        return "https://{$shop->domain}/admin/api/{$shop->api_version}/graphql.json";
    }
}
