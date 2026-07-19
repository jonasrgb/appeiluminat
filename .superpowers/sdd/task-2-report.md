# Task 2 Report: Pure Shopify JSONL Parser

## Status

DONE_WITH_CONCERNS

## Files Changed

- `app/Services/Shopify/CatalogAuditJsonlParser.php`
  - Added a pure `parse(string $jsonl, Shop $shop): array` implementation.
  - Reconstructs product, image, and variant relationships from Shopify GIDs and `__parentId` values without relying on database or network access.
  - Retains active products only, treats only `ProductImage` rows as images, ignores blank SKUs, normalizes SKUs by trimming and lowercasing, and counts duplicate SKU groups and affected rows.
  - Emits schema-aligned finding rows with stable fingerprints and Shopify Admin product URLs.
  - Sorts products, variants, SKU groups, and findings to keep results independent of JSONL row order.

- `tests/Unit/CatalogAuditJsonlParserTest.php`
  - Added focused unit coverage for order-independent reconstruction, missing images, duplicate SKUs across products, draft products, blank SKUs, duplicate variants within one product, image rows before product rows, and non-image video media.
  - Added assertions for finding metadata, fingerprints, normalization, counts, and Admin URLs.

## TDD Evidence

The initial focused test run was intentionally red because the parser class did not exist:

```text
Tests:    4 failed (0 assertions)
Error:    Class "App\\Services\\Shopify\\CatalogAuditJsonlParser" not found
```

## Verification

Command:

```bash
php artisan test tests/Unit/CatalogAuditJsonlParserTest.php
```

Final output:

```text
PASS  Tests\\Unit\\CatalogAuditJsonlParserTest
Tests:    4 passed (21 assertions)
Duration: 0.06s
```

Additional checks:

```text
No syntax errors detected in app/Services/Shopify/CatalogAuditJsonlParser.php
No syntax errors detected in tests/Unit/CatalogAuditJsonlParserTest.php
```

```text
vendor/bin/pint --test app/Services/Shopify/CatalogAuditJsonlParser.php tests/Unit/CatalogAuditJsonlParserTest.php
PASS  2 files
```

No commit was created.

## Fix Report: Complete Fingerprint Bounds

### Changes

- Changed missing-image fingerprints to `missing_image:` plus the SHA-256 digest of the complete product GID.
- Changed duplicate-SKU fingerprints to `duplicate_sku:` plus the SHA-256 digest of a byte-length-prefixed normalized SKU, separator, and complete variant GID identity.
- Kept both type prefixes and deterministic SHA-256 output; every fingerprint is now 78 bytes regardless of GID or SKU length.
- Added regression coverage for thousand-digit numeric Product and ProductVariant GIDs, including the 255-byte bound and distinct identity assertions.
- Updated exact fingerprint expectations and removed a duplicate-SKU test's incidental ordering assumption.

### TDD Evidence

The new long-GID test initially failed against the old parser because the missing-image fingerprint was 1108 bytes. The updated duplicate expectations also failed against the old SKU-hash-plus-GID format. After the parser change, the focused suite passed.

### Verification

```text
php artisan test tests/Unit/CatalogAuditJsonlParserTest.php --colors=never
PASS  Tests\Unit\CatalogAuditJsonlParserTest
Tests:    9 passed (51 assertions)
```

```text
php -l app/Services/Shopify/CatalogAuditJsonlParser.php
No syntax errors detected

php -l tests/Unit/CatalogAuditJsonlParserTest.php
No syntax errors detected
```

No commit was created.

## Concerns

- PHPUnit emits the existing warning that `phpunit.xml` uses a deprecated XML configuration schema. It does not fail the focused test run.
- The full test suite was not run; verification was limited to the requested focused parser test and syntax checks.
- The worktree was already dirty with unrelated changes; those files were left untouched.

## Fix Report

### Changes

- Deduplicated variant nodes by valid variant GID before grouping SKUs and generating findings.
- Rejected malformed or non-numeric `Product`, `ProductVariant`, and `ProductImage` resource IDs, including invalid JSON lines and invalid product parents.
- Generated Admin URLs from the first hostname label.
- Added regression coverage for invalid JSON, malformed GIDs, duplicate variant nodes, and nested shop hostnames.

### Verification

Command:

```bash
php artisan test tests/Unit/CatalogAuditJsonlParserTest.php --colors=never
```

Output:

```text
WARN  Your XML configuration validates against a deprecated schema. Migrate your XML configuration using "--migrate-configuration"!

PASS  Tests\Unit\CatalogAuditJsonlParserTest
✓ it reconstructs order independent active products images and duplicate skus
✓ it ignores draft products blank skus and non image media
✓ it counts duplicate variants in one product and keeps original sku
✓ it produces the same findings when jsonl order changes
✓ it ignores invalid json and malformed resource gids
✓ it deduplicates duplicate variant nodes by valid variant gid
✓ it uses the first hostname label for admin urls

Tests:    7 passed (30 assertions)
Duration: 0.08s
```

Command:

```bash
php -l app/Services/Shopify/CatalogAuditJsonlParser.php && php -l tests/Unit/CatalogAuditJsonlParserTest.php
```

Output:

```text
No syntax errors detected in app/Services/Shopify/CatalogAuditJsonlParser.php
No syntax errors detected in tests/Unit/CatalogAuditJsonlParserTest.php
```

No commit was created.

## Fix Report: Bounded Duplicate SKU Fingerprints

### Changes

- Replaced the normalized SKU segment in duplicate SKU fingerprints with its deterministic SHA-256 hash.
- Preserved the `duplicate_sku` prefix and variant GID so variant identity and order-independent output remain stable.
- Left missing-image fingerprints unchanged.
- Updated existing exact fingerprint expectations and added regression coverage for two distinct 255-character normalized SKUs.

### TDD Evidence

The new regression initially failed because the existing full-SKU fingerprint produced zero fingerprints at or below 255 characters. After the hash change, the same focused test passed.

### Verification

```text
PASS  Tests\Unit\CatalogAuditJsonlParserTest
Tests:    8 passed (36 assertions)
```

```text
No syntax errors detected in app/Services/Shopify/CatalogAuditJsonlParser.php
No syntax errors detected in tests/Unit/CatalogAuditJsonlParserTest.php
```

No commit was created.
