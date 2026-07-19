<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Shopify\CatalogAuditBulkService;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CatalogAuditBulkServiceTest extends TestCase
{
    public function test_it_downloads_an_active_product_snapshot_through_a_read_only_bulk_operation(): void
    {
        $snapshotUrl = 'https://storage.shopify.test/catalog-audit.jsonl';

        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($snapshotUrl) {
            if ($request->url() === $snapshotUrl) {
                return Http::response("{\"id\":\"gid://shopify/Product/1\"}\n");
            }

            $query = $this->query($request);
            if (str_contains($query, 'RunCatalogAuditBulkOperation')) {
                return Http::response($this->startedOperation());
            }

            $this->assertStringContainsString('CurrentCatalogAuditBulkOperation', $query);
            $this->assertStringContainsString('"variables":{}', $request->body());

            return Http::response([
                'data' => ['currentBulkOperation' => [
                    'id' => 'gid://shopify/BulkOperation/1',
                    'status' => 'COMPLETED',
                    'errorCode' => null,
                    'url' => $snapshotUrl,
                    'partialDataUrl' => null,
                ]],
            ]);
        });

        $snapshot = app(CatalogAuditBulkService::class)->downloadSnapshot($this->shop(), 1, 0);

        $this->assertSame("{\"id\":\"gid://shopify/Product/1\"}\n", $snapshot);
        Http::assertSent(function (Request $request): bool {
            $query = $this->query($request);
            $variables = (array) ($request->data()['variables'] ?? []);
            $bulkQuery = $variables['query'] ?? '';

            return $request->url() === $this->endpoint()
                && str_contains($query, 'mutation RunCatalogAuditBulkOperation')
                && str_contains($query, 'bulkOperationRunQuery')
                && $request->hasHeader('X-Shopify-Access-Token', 'catalog-audit-token')
                && str_contains($bulkQuery, 'products(query: "status:active")')
                && str_contains($bulkQuery, 'images(first: 1)')
                && str_contains($bulkQuery, 'variants { edges { node { id legacyResourceId title sku } } }');
        });
        Http::assertNotSent(fn (Request $request): bool => $request->url() === $this->endpoint()
            && ! str_contains($this->query($request), 'RunCatalogAuditBulkOperation')
            && str_contains($this->query($request), 'mutation'));
        Http::assertSentCount(3);
    }

    public function test_it_rejects_bulk_operation_user_errors(): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->endpoint() => Http::response([
            'data' => ['bulkOperationRunQuery' => [
                'bulkOperation' => null,
                'userErrors' => [['field' => ['query'], 'message' => 'Query is invalid.']],
            ]],
        ])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bulkOperationRunQuery userErrors');

        app(CatalogAuditBulkService::class)->downloadSnapshot($this->shop(), 1, 0);
    }

    public function test_it_rejects_a_terminal_failed_operation(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($this->query($request), 'RunCatalogAuditBulkOperation')) {
                return Http::response($this->startedOperation());
            }

            return Http::response([
                'data' => ['currentBulkOperation' => [
                    'id' => 'gid://shopify/BulkOperation/1',
                    'status' => 'FAILED',
                    'errorCode' => 'ACCESS_DENIED',
                    'url' => null,
                    'partialDataUrl' => 'https://storage.shopify.test/partial.jsonl',
                ]],
            ]);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('status FAILED (errorCode: ACCESS_DENIED)');

        app(CatalogAuditBulkService::class)->downloadSnapshot($this->shop(), 1, 0);
    }

    public function test_it_rejects_when_the_current_operation_id_changes(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($this->query($request), 'RunCatalogAuditBulkOperation')) {
                return Http::response($this->startedOperation());
            }

            return Http::response([
                'data' => ['currentBulkOperation' => [
                    'id' => 'gid://shopify/BulkOperation/2',
                    'status' => 'RUNNING',
                    'errorCode' => null,
                    'url' => null,
                    'partialDataUrl' => null,
                ]],
            ]);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('id changed unexpectedly');

        app(CatalogAuditBulkService::class)->downloadSnapshot($this->shop(), 1, 0);
    }

    public function test_it_times_out_while_waiting_for_completion(): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->endpoint() => Http::response($this->startedOperation())]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Timeout while waiting for bulk operation completion');

        app(CatalogAuditBulkService::class)->downloadSnapshot($this->shop(), 0, 0);
    }

    public function test_it_rejects_a_completed_operation_without_a_result_url(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($this->query($request), 'RunCatalogAuditBulkOperation')) {
                return Http::response($this->startedOperation());
            }

            return Http::response([
                'data' => ['currentBulkOperation' => [
                    'id' => 'gid://shopify/BulkOperation/1',
                    'status' => 'COMPLETED',
                    'errorCode' => null,
                    'url' => null,
                    'partialDataUrl' => null,
                ]],
            ]);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('completed without result URL');

        app(CatalogAuditBulkService::class)->downloadSnapshot($this->shop(), 1, 0);
    }

    public function test_it_uses_partial_data_url_only_for_a_completed_operation(): void
    {
        $partialUrl = 'https://storage.shopify.test/catalog-audit-partial.jsonl';

        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($partialUrl) {
            if ($request->url() === $partialUrl) {
                return Http::response("{\"id\":\"gid://shopify/Product/2\"}\n");
            }

            if (str_contains($this->query($request), 'RunCatalogAuditBulkOperation')) {
                return Http::response($this->startedOperation());
            }

            return Http::response([
                'data' => ['currentBulkOperation' => [
                    'id' => 'gid://shopify/BulkOperation/1',
                    'status' => 'COMPLETED',
                    'errorCode' => null,
                    'url' => null,
                    'partialDataUrl' => $partialUrl,
                ]],
            ]);
        });

        $snapshot = app(CatalogAuditBulkService::class)->downloadSnapshot($this->shop(), 1, 0);

        $this->assertSame("{\"id\":\"gid://shopify/Product/2\"}\n", $snapshot);
        Http::assertSentCount(3);
    }

    public function test_it_caps_poll_sleep_at_the_remaining_deadline(): void
    {
        $now = 0.0;
        $sleeps = [];

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($this->query($request), 'RunCatalogAuditBulkOperation')) {
                return Http::response($this->startedOperation());
            }

            return Http::response(['data' => ['currentBulkOperation' => [
                'id' => 'gid://shopify/BulkOperation/1',
                'status' => 'RUNNING',
                'errorCode' => null,
                'url' => null,
                'partialDataUrl' => null,
            ]]]);
        });

        try {
            $this->service(
                sleeper: function (float $seconds) use (&$now, &$sleeps): void {
                    $sleeps[] = $seconds;
                    $now += $seconds;
                },
                clock: static function () use (&$now): float {
                    return $now;
                },
            )->downloadSnapshot($this->shop(), 1, 10);
            $this->fail('Expected the poll to time out.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Timeout while waiting for bulk operation completion.', $exception->getMessage());
        }

        $this->assertSame([1.0], $sleeps);
        Http::assertSentCount(2);
    }

    public function test_it_retries_a_graphql_rate_limit_using_retry_after(): void
    {
        $snapshotUrl = 'https://storage.shopify.test/catalog-audit.jsonl';
        $sleeps = [];
        $startAttempts = 0;

        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($snapshotUrl, &$startAttempts) {
            if ($request->url() === $snapshotUrl) {
                return Http::response("{\"id\":\"gid://shopify/Product/1\"}\n");
            }

            if (str_contains($this->query($request), 'RunCatalogAuditBulkOperation')) {
                $startAttempts++;

                return $startAttempts === 1
                    ? Http::response('', 429, ['Retry-After' => '2'])
                    : Http::response($this->startedOperation());
            }

            return Http::response($this->completedOperation($snapshotUrl));
        });

        $snapshot = $this->service(sleeper: function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        })->downloadSnapshot($this->shop(), 1, 0);

        $this->assertSame("{\"id\":\"gid://shopify/Product/1\"}\n", $snapshot);
        $this->assertSame([2.0], $sleeps);
        Http::assertSentCount(4);
    }

    public function test_it_does_not_retry_start_after_a_connection_exception(): void
    {
        $startAttempts = 0;

        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$startAttempts) {
            $this->assertStringContainsString('RunCatalogAuditBulkOperation', $this->query($request));
            $startAttempts++;

            throw new ConnectionException('Connection refused.');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('start may have been accepted');

        try {
            $this->service(sleeper: static function (float $seconds): void {})->downloadSnapshot($this->shop(), 1, 0);
        } finally {
            $this->assertSame(1, $startAttempts);
        }
    }

    public function test_it_does_not_retry_start_after_a_server_error(): void
    {
        $startAttempts = 0;

        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$startAttempts) {
            $this->assertStringContainsString('RunCatalogAuditBulkOperation', $this->query($request));
            $startAttempts++;

            return Http::response('', 503);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('start may have been accepted');

        try {
            $this->service(sleeper: static function (float $seconds): void {})->downloadSnapshot($this->shop(), 1, 0);
        } finally {
            $this->assertSame(1, $startAttempts);
        }
    }

    public function test_it_caps_the_poll_request_timeout_to_the_remaining_deadline(): void
    {
        $now = 0.0;
        $pollTimeouts = [];

        Http::preventStrayRequests();
        Http::fake(function (Request $request, array $options) use (&$now, &$pollTimeouts) {
            if (str_contains($this->query($request), 'RunCatalogAuditBulkOperation')) {
                return Http::response($this->startedOperation());
            }

            $pollTimeouts[] = $options['timeout'];
            $now = 2.0;

            return Http::response(['data' => ['currentBulkOperation' => [
                'id' => 'gid://shopify/BulkOperation/1',
                'status' => 'RUNNING',
                'errorCode' => null,
                'url' => null,
                'partialDataUrl' => null,
            ]]]);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Timeout while waiting for bulk operation completion');

        try {
            $this->service(
                sleeper: static function (float $seconds): void {},
                clock: static function () use (&$now): float {
                    return $now;
                },
            )->downloadSnapshot($this->shop(), 2, 1);
        } finally {
            $this->assertSame([2], $pollTimeouts);
        }
    }

    public function test_it_does_not_round_a_poll_request_timeout_up_past_the_remaining_deadline(): void
    {
        $now = 0.0;
        $pollTimeouts = [];
        $pollAttempts = 0;

        Http::preventStrayRequests();
        Http::fake(function (Request $request, array $options) use (&$now, &$pollAttempts, &$pollTimeouts) {
            if (str_contains($this->query($request), 'RunCatalogAuditBulkOperation')) {
                return Http::response($this->startedOperation());
            }

            $pollAttempts++;
            $pollTimeouts[] = $options['timeout'];
            $now = $pollAttempts === 1 ? 0.6 : 2.0;

            return Http::response(['data' => ['currentBulkOperation' => [
                'id' => 'gid://shopify/BulkOperation/1',
                'status' => 'RUNNING',
                'errorCode' => null,
                'url' => null,
                'partialDataUrl' => null,
            ]]]);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Timeout while waiting for bulk operation completion');

        try {
            $this->service(
                sleeper: static function (float $seconds): void {},
                clock: static function () use (&$now): float {
                    return $now;
                },
            )->downloadSnapshot($this->shop(), 2, 0);
        } finally {
            $this->assertSame([2, 1], $pollTimeouts);
        }
    }

    public function test_it_caps_retry_after_sleep_to_the_poll_deadline_without_retrying_afterwards(): void
    {
        $now = 0.0;
        $sleeps = [];
        $pollAttempts = 0;
        $pollTimeouts = [];

        Http::preventStrayRequests();
        Http::fake(function (Request $request, array $options) use (&$pollAttempts, &$pollTimeouts) {
            if (str_contains($this->query($request), 'RunCatalogAuditBulkOperation')) {
                return Http::response($this->startedOperation());
            }

            $pollAttempts++;
            $pollTimeouts[] = $options['timeout'];

            return Http::response('', 429, ['Retry-After' => '60']);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Timeout while waiting for bulk operation completion');

        try {
            $this->service(
                sleeper: function (float $seconds) use (&$now, &$sleeps): void {
                    $sleeps[] = $seconds;
                    $now += $seconds;
                },
                clock: static function () use (&$now): float {
                    return $now;
                },
            )->downloadSnapshot($this->shop(), 1, 1);
        } finally {
            $this->assertSame(1, $pollAttempts);
            $this->assertSame([1], $pollTimeouts);
            $this->assertSame([1.0], $sleeps);
        }
    }

    public function test_it_retries_a_transient_result_download_failure(): void
    {
        $snapshotUrl = 'https://storage.shopify.test/catalog-audit.jsonl';
        $downloadAttempts = 0;

        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($snapshotUrl, &$downloadAttempts) {
            if ($request->url() === $snapshotUrl) {
                $downloadAttempts++;

                return $downloadAttempts === 1
                    ? Http::response('', 503)
                    : Http::response("{\"id\":\"gid://shopify/Product/1\"}\n");
            }

            if (str_contains($this->query($request), 'RunCatalogAuditBulkOperation')) {
                return Http::response($this->startedOperation());
            }

            return Http::response($this->completedOperation($snapshotUrl));
        });

        $snapshot = $this->service(sleeper: static function (float $seconds): void {})->downloadSnapshot($this->shop(), 1, 0);

        $this->assertSame("{\"id\":\"gid://shopify/Product/1\"}\n", $snapshot);
        $this->assertSame(2, $downloadAttempts);
        Http::assertSentCount(4);
    }

    public function test_it_retries_a_result_download_connection_exception(): void
    {
        $snapshotUrl = 'https://storage.shopify.test/catalog-audit.jsonl';
        $downloadAttempts = 0;

        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($snapshotUrl, &$downloadAttempts) {
            if ($request->url() === $snapshotUrl) {
                $downloadAttempts++;

                if ($downloadAttempts === 1) {
                    throw new ConnectionException('Connection refused.');
                }

                return Http::response("{\"id\":\"gid://shopify/Product/1\"}\n");
            }

            if (str_contains($this->query($request), 'RunCatalogAuditBulkOperation')) {
                return Http::response($this->startedOperation());
            }

            return Http::response($this->completedOperation($snapshotUrl));
        });

        $snapshot = $this->service(sleeper: static function (float $seconds): void {})->downloadSnapshot($this->shop(), 1, 0);

        $this->assertSame("{\"id\":\"gid://shopify/Product/1\"}\n", $snapshot);
        $this->assertSame(2, $downloadAttempts);
    }

    public function test_it_does_not_retry_non_transient_graphql_client_errors(): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->endpoint() => Http::response('', 422)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shopify GraphQL HTTP error on start: 422');

        $this->service(sleeper: static function (float $seconds): void {})->downloadSnapshot($this->shop(), 1, 0);
    }

    public function test_it_rejects_malformed_graphql_json(): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->endpoint() => Http::response('{')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shopify GraphQL response contained invalid JSON on start');

        $this->service()->downloadSnapshot($this->shop(), 1, 0);
    }

    public function test_it_rejects_graphql_root_errors(): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->endpoint() => Http::response([
            'errors' => [['message' => 'Access denied.']],
        ])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shopify GraphQL errors on start');

        $this->service()->downloadSnapshot($this->shop(), 1, 0);
    }

    public function test_it_rejects_missing_required_graphql_data_paths(): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->endpoint() => Http::response(['data' => []])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shopify GraphQL response missing data.bulkOperationRunQuery on start');

        $this->service()->downloadSnapshot($this->shop(), 1, 0);
    }

    public function test_it_rejects_a_null_current_bulk_operation_response(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($this->query($request), 'RunCatalogAuditBulkOperation')) {
                return Http::response($this->startedOperation());
            }

            return Http::response(['data' => ['currentBulkOperation' => null]]);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shopify GraphQL response missing data.currentBulkOperation on polling');

        $this->service()->downloadSnapshot($this->shop(), 1, 0);
    }

    /**
     * @dataProvider invalidStartOperationProvider
     */
    public function test_it_rejects_invalid_start_operation_field_types(array $operation): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->endpoint() => Http::response([
            'data' => ['bulkOperationRunQuery' => [
                'bulkOperation' => $operation,
                'userErrors' => [],
            ]],
        ])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('response shape error on start');

        $this->service()->downloadSnapshot($this->shop(), 1, 0);
    }

    public static function invalidStartOperationProvider(): array
    {
        return [
            'empty id' => [[
                'id' => '',
                'status' => 'CREATED',
            ]],
            'wrong gid type' => [[
                'id' => 'gid://shopify/Product/1',
                'status' => 'CREATED',
            ]],
            'missing status' => [[
                'id' => 'gid://shopify/BulkOperation/1',
            ]],
            'non-string status' => [[
                'id' => 'gid://shopify/BulkOperation/1',
                'status' => ['CREATED'],
            ]],
        ];
    }

    /**
     * @dataProvider invalidPollOperationProvider
     */
    public function test_it_rejects_invalid_poll_operation_field_types(array $operation): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($operation) {
            if (str_contains($this->query($request), 'RunCatalogAuditBulkOperation')) {
                return Http::response($this->startedOperation());
            }

            return Http::response(['data' => ['currentBulkOperation' => $operation]]);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('response shape error on polling');

        $this->service()->downloadSnapshot($this->shop(), 1, 0);
    }

    public static function invalidPollOperationProvider(): array
    {
        $validOperation = [
            'id' => 'gid://shopify/BulkOperation/1',
            'status' => 'RUNNING',
            'errorCode' => null,
            'url' => null,
            'partialDataUrl' => null,
        ];

        return [
            'wrong gid type' => [array_replace($validOperation, ['id' => 'gid://shopify/Product/1'])],
            'missing status' => [array_diff_key($validOperation, ['status' => true])],
            'non-string status' => [array_replace($validOperation, ['status' => ['RUNNING']])],
            'non-string url' => [array_replace($validOperation, ['url' => ['url']])],
            'non-string partial data url' => [array_replace($validOperation, ['partialDataUrl' => ['url']])],
            'non-string error code' => [array_replace($validOperation, ['errorCode' => ['code']])],
        ];
    }

    private function shop(): Shop
    {
        return new Shop([
            'id' => 9,
            'domain' => 'catalog-audit.myshopify.com',
            'access_token' => 'catalog-audit-token',
            'api_version' => '2025-10',
            'is_active' => true,
        ]);
    }

    private function endpoint(): string
    {
        return 'https://catalog-audit.myshopify.com/admin/api/2025-10/graphql.json';
    }

    private function query(Request $request): string
    {
        return (string) ($request->data()['query'] ?? '');
    }

    private function startedOperation(): array
    {
        return [
            'data' => ['bulkOperationRunQuery' => [
                'bulkOperation' => [
                    'id' => 'gid://shopify/BulkOperation/1',
                    'status' => 'CREATED',
                ],
                'userErrors' => [],
            ]],
        ];
    }

    private function completedOperation(string $snapshotUrl): array
    {
        return [
            'data' => ['currentBulkOperation' => [
                'id' => 'gid://shopify/BulkOperation/1',
                'status' => 'COMPLETED',
                'errorCode' => null,
                'url' => $snapshotUrl,
                'partialDataUrl' => null,
            ]],
        ];
    }

    private function service(?Closure $sleeper = null, ?Closure $clock = null): CatalogAuditBulkService
    {
        return new CatalogAuditBulkService(sleeper: $sleeper, clock: $clock);
    }
}
