# Safe BEM Media Orchestration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep existing product images visible until every BEM replacement image is confirmed ready, and recover source media when a create webhook arrives before Shopify finishes processing it.

**Architecture:** `BemShopifyStagedUploadService` owns the append/verify/delete transaction boundary. A focused source-create media resolver reads live Shopify media; target create jobs and source watermark jobs hydrate delayed media and wait while Shopify is still processing it.

**Tech Stack:** Laravel queues, Shopify Admin GraphQL, Laravel HTTP fakes, PHPUnit.

## Global Constraints

- Do not change watermark rendering, file naming, manifests, or parent identity.
- Do not include Industrial or Bulgaria in live E2E verification.
- Live test products must remain DRAFT and have zero publications.
- Never delete old image media until the exact newly created media IDs are READY with URLs.

---

### Task 1: Atomic-safe image replacement

**Files:**
- Modify: `app/Services/Shopify/BemWatermark/BemShopifyStagedUploadService.php`
- Modify: `app/Jobs/BemApplySourceProductWatermark.php`
- Test: `tests/Feature/BemWatermarkFlowTest.php`

**Interfaces:**
- Produces: `waitForReadyProductMedia(Shop $shop, string $productGid, array $mediaIds): array`.
- Produces: append results containing `media_id`, `status`, and final `watermarked_url`.

- [x] Write a failing feature test asserting `productCreateMedia` runs before `productDeleteMedia`.
- [x] Write a failing feature test asserting timeout/FAILED media prevents deletion.
- [x] Change both processed-file and URL replacement paths to snapshot image IDs, append, verify exact IDs, then delete the snapshot.
- [x] Reuse exact-ID readiness verification in source-create watermarking.
- [x] Run `php artisan test tests/Feature/BemWatermarkFlowTest.php`.

### Task 2: Recover delayed media on product create

**Files:**
- Create: `app/Services/Shopify/BemWatermark/BemSourceCreateMediaResolver.php`
- Modify: `app/Jobs/ReplicateProductCreateToShop.php`
- Test: `tests/Feature/BemSourceCreateMediaResolverTest.php`
- Test: `tests/Unit/ReplicateProductCreateIdentityContractTest.php`

**Interfaces:**
- Produces: `resolve(Shop $source, string $productGid): array{status: string, images: array}` where status is `ready`, `processing`, or `empty`.

- [x] Write failing tests for READY, PROCESSING, and genuinely empty source media.
- [x] Implement the read-only GraphQL resolver.
- [x] Hydrate empty eligible create payloads before target BEM preparation and source watermarking.
- [x] Release jobs during PROCESSING and use a bounded grace period for an empty live response.
- [x] Run the focused resolver and create identity tests.

### Task 3: Regression and live verification

**Files:**
- Test: existing BEM and identity suites.

- [x] Run PHP syntax checks and `git diff --check`.
- [x] Run all BEM feature tests and strict identity tests.
- [x] Restart only the Laravel queue worker after tests pass.
- [x] Create a DRAFT source product with three delayed images and verify target creation.
- [x] Verify safe append/READY/delete behavior with success and failure regression tests.
- [x] Confirm source and all three targets are DRAFT with zero publications.
