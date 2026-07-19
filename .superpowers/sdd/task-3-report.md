# Task 3 Report: Catalog Audit Bulk Reader

## Files

- Created `app/Services/Shopify/CatalogAuditBulkService.php`
- Created `tests/Feature/CatalogAuditBulkServiceTest.php`
- Created this report: `.superpowers/sdd/task-3-report.md`

No unrelated files were modified or reverted. No commit was made.

## Implementation

`CatalogAuditBulkService::downloadSnapshot(Shop $shop, int $timeoutSeconds, int $pollSeconds): string`:

- Uses the provided shop domain, configured API version, and access token.
- Starts only `bulkOperationRunQuery`; its embedded query reads active products, one image, and variants including SKU.
- Polls `currentBulkOperation(type: QUERY)` and rejects a changed operation ID.
- Rejects GraphQL user errors, failed/canceled/expired operations, timeouts, and completed operations without a result URL.
- Downloads `url`, falling back to `partialDataUrl` only after `COMPLETED` is confirmed.
- Uses Laravel `Http`, with every test request faked and `Http::preventStrayRequests()` enabled.

## TDD Evidence

### Red

Command:

```bash
php artisan test tests/Feature/CatalogAuditBulkServiceTest.php
```

Observed output before the service existed:

```text
WARN  Your XML configuration validates against a deprecated schema. Migrate your XML configuration using "--migrate-configuration"!

FAIL  Tests\Feature\CatalogAuditBulkServiceTest
⨯ it downloads an active product snapshot through a read only bulk op… 0.10s
⨯ it rejects bulk operation user errors                                0.01s
⨯ it rejects a terminal failed operation                               0.01s
⨯ it rejects when the current operation id changes                     0.01s
⨯ it times out while waiting for completion                            0.01s
⨯ it rejects a completed operation without a result url                0.01s
⨯ it uses partial data url only for a completed operation              0.01s

FAILED  Tests\Feature\CatalogAuditBulkServiceTest
BindingResolutionException
Target class [App\Services\Shopify\CatalogAuditBulkService] does not exist.

Tests:    7 failed (5 assertions)
Duration: 0.21s
```

The failure was expected: the test suite referenced the required service before its implementation existed.

### Green

Command:

```bash
php artisan test tests/Feature/CatalogAuditBulkServiceTest.php
```

Exact final test output:

```text
WARN  Your XML configuration validates against a deprecated schema. Migrate your XML configuration using "--migrate-configuration"!

PASS  Tests\Feature\CatalogAuditBulkServiceTest
✓ it downloads an active product snapshot through a read only bulk op… 0.12s
✓ it rejects bulk operation user errors                                0.01s
✓ it rejects a terminal failed operation                               0.01s
✓ it rejects when the current operation id changes                     0.01s
✓ it times out while waiting for completion                            0.01s
✓ it rejects a completed operation without a result url                0.01s
✓ it uses partial data url only for a completed operation              0.01s

Tests:    7 passed (17 assertions)
Duration: 0.23s
```

Additional final checks:

```text
No syntax errors detected in app/Services/Shopify/CatalogAuditBulkService.php
No syntax errors detected in tests/Feature/CatalogAuditBulkServiceTest.php
```

`git diff --check -- app/Services/Shopify/CatalogAuditBulkService.php tests/Feature/CatalogAuditBulkServiceTest.php` completed with no output and exit code 0.

## Concerns

- PHPUnit reports an existing deprecated XML schema warning. This task did not alter PHPUnit configuration.
- Only the requested feature test file was run; the full repository test suite was not run.

## Fix Report

### Changes

- Polling now sleeps for at most the time remaining before the deadline, including when `pollSeconds` exceeds `timeoutSeconds`. The test uses injected time and sleep callbacks, so it does not wait in real time.
- GraphQL requests and signed-result downloads now retry only transient failures: HTTP 429, HTTP 5xx, and `ConnectionException`. Retries are limited to three attempts with exponential backoff; 429 honors a numeric or HTTP-date `Retry-After` value. Other 4xx responses are returned immediately and reported as errors.
- GraphQL handling now requires valid JSON, rejects root `errors`, and validates `data.bulkOperationRunQuery` for start requests and a non-null `data.currentBulkOperation` for polls. Invalid or missing responses produce phase-specific errors instead of continuing with ambiguous state.
- Operation-ID verification, terminal status handling, the read-only `bulkOperationRunQuery` mutation, and the `images(first: 1)` image check remain unchanged.

### Serialization Dependency

Task 5 must enforce one bulk operation per shop with the queued job's `WithoutOverlapping` middleware. This service deliberately does not add cache locks because file and database cache drivers are not reliable for this serialization boundary.

### Verification

- `php artisan test tests/Feature/CatalogAuditBulkServiceTest.php`: 16 passing tests, 38 assertions.
- `php -l app/Services/Shopify/CatalogAuditBulkService.php`: no syntax errors.
- `php -l tests/Feature/CatalogAuditBulkServiceTest.php`: no syntax errors.

## Final Review Addendum

### Changes

- `bulkOperationRunQuery` now retries only explicit HTTP 429 rejections. A connection exception or HTTP 5xx response fails immediately with an ambiguity-preserving message, so one `downloadSnapshot` call cannot submit the non-idempotent start mutation twice after uncertain acceptance.
- Polling carries the completion deadline through every GraphQL request and retry. Each poll request receives an HTTP timeout capped to the remaining whole seconds, with a one-second minimum for a positive sub-second remainder. Retry and `Retry-After` sleeps are capped to the remaining deadline and do not issue another request once it expires.
- Start payloads now require a valid nonempty BulkOperation GID and known status string. Poll payloads require the same `id` and `status`, plus nullable-string `url`, `partialDataUrl`, and `errorCode` fields. Invalid shapes fail immediately with phase-specific response-shape errors.
- Result downloads retain the existing bounded retry behavior and are intentionally not constrained by the completion deadline. The active-product image and variant query remains unchanged and read-only.

### TDD Evidence

- Red: added tests demonstrated three start attempts on connection and HTTP 503 failures, a fixed 90-second poll timeout, a retry after deadline expiry, and malformed operation fields reaching later logic.
- Green: the focused suite verifies one start attempt for connection and 5xx failures; deterministic clock/sleeper coverage for request timeout and retry-delay deadline caps; and start/poll field-type validation.

### Final Verification

- `vendor/bin/pint app/Services/Shopify/CatalogAuditBulkService.php tests/Feature/CatalogAuditBulkServiceTest.php`: completed, formatting two owned files.
- `TELESCOPE_ENABLED=false XDG_CONFIG_HOME=/tmp php artisan test tests/Feature/CatalogAuditBulkServiceTest.php`: 31 passing tests, 77 assertions.
- `php -l app/Services/Shopify/CatalogAuditBulkService.php`: no syntax errors.
- `php -l tests/Feature/CatalogAuditBulkServiceTest.php`: no syntax errors.

No commit was made.
