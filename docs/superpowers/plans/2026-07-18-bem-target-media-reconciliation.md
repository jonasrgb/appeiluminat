# BEM Target Media Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make BEM source-update retries inspect and repair incomplete target media even when the source itself is already a no-op.

**Architecture:** Add a focused target reconciler that reads live target images and `prod.watermarked`, evaluates them against current clean backup images, and repairs only unhealthy targets. Keep orchestration in `BemSyncBackupManifestFromSourceUpdate`, continue after individual target failures, and throw an aggregate error after every target was attempted so the existing queue retry remains effective.

**Tech Stack:** Laravel 10, PHP 8, Shopify GraphQL Admin API, Laravel HTTP fakes, Mockery, PHPUnit.

## Global Constraints

- Do not change product identity resolution, watermark rendering, variants, status, publishing, or backup manifest schema.
- Never treat zero desired backup images as healthy.
- Healthy targets must receive no Shopify writes.
- Target failures must not prevent later targets from being attempted.
- Existing BEM target shops and strict `custom.parentproduct` mirror bootstrap remain authoritative.
- No commit is created unless the user explicitly requests one because the live workspace contains unrelated changes.

---

### Task 1: Target Health Evaluation And Repair

**Files:**
- Create: `app/Services/Shopify/BemWatermark/BemTargetMediaReconciler.php`
- Test: `tests/Feature/BemTargetMediaReconcilerTest.php`

**Interfaces:**
- Consumes: `ProductMirror`, target and backup `Shop` models, backup product identifiers, title, and normalized backup images.
- Produces: `reconcile(...): array{status: string, repaired: bool, reasons: array<int,string>, expected_images: int, actual_images: int, manifest_images: int}`.

- [ ] **Step 1: Write failing health tests**

Add tests proving that zero live images, a missing manifest, mismatched live/manifest URLs, and mismatched manifest/backup source URLs are unhealthy, while a positionally matching target is healthy.

- [ ] **Step 2: Run tests and verify RED**

Run:

```bash
TELESCOPE_ENABLED=false XDG_CONFIG_HOME=/tmp php artisan test tests/Feature/BemTargetMediaReconcilerTest.php
```

Expected: failure because `BemTargetMediaReconciler` does not exist.

- [ ] **Step 3: Implement target inspection**

The service fetches:

```graphql
product(id: $id) {
  images(first: 250) { nodes { id url altText } }
  metafield(namespace: "prod", key: "watermarked") { value }
}
```

It canonicalizes URLs with `BemImageIdentityService` and returns explicit reason codes such as `missing_live_images`, `image_count_mismatch`, `manifest_count_mismatch`, `watermarked_url_mismatch`, and `backup_source_url_mismatch`.

- [ ] **Step 4: Add failing repair tests**

Add tests proving an unhealthy target calls the existing image processor, staged upload service, and metafield service exactly once, updates the mirror snapshot after success, and cleans temporary files. Add a healthy-target test asserting those collaborators receive no write calls.

- [ ] **Step 5: Implement minimal repair behavior**

Use existing collaborators:

```php
$processed = $imageProcessor->process($target, $title, $backupImages);
$uploaded = $uploadService->replaceProductImages($target, $mirror->target_product_gid, $processed['processed']);
$metafieldService->update($target, $mirror->target_product_gid, $payload);
```

Update `ProductMirror::last_snapshot` only after both media replacement and metafield update succeed. Always clean temporary paths in `finally`.

- [ ] **Step 6: Run reconciler tests and verify GREEN**

Run the Task 1 test command and expect all tests to pass.

---

### Task 2: Source Job Retry Orchestration

**Files:**
- Modify: `app/Jobs/BemSyncBackupManifestFromSourceUpdate.php`
- Modify: `tests/Feature/BemWatermarkUpdateManifestTest.php`

**Interfaces:**
- Consumes: `BemTargetMediaReconciler::reconcile(...)` from Task 1.
- Produces: source no-op behavior that still reconciles all target mirrors and aggregate retry errors after all targets are attempted.

- [ ] **Step 1: Write failing source no-op regression test**

Add a test that models a source already matching `prod.watermarked` and an incomplete target. Assert the job invokes target reconciliation instead of returning at the source no-op branch.

- [ ] **Step 2: Write failing failure-isolation test**

Model two target mirrors where the first reconciler call throws and the second succeeds. Assert both are attempted and the job throws an aggregate `RuntimeException` after the loop.

- [ ] **Step 3: Run tests and verify RED**

Run:

```bash
TELESCOPE_ENABLED=false XDG_CONFIG_HOME=/tmp php artisan test tests/Feature/BemWatermarkUpdateManifestTest.php
```

Expected: new regression tests fail because the job still returns before target reconciliation and stops on the first target error.

- [ ] **Step 4: Implement orchestration**

Replace the source-level early return with a target reconciliation pass using current backup images. Replace the inline target media loop in the non-no-op path with the same pass. Catch target exceptions individually, log them, continue processing, and throw one aggregate error after backup manifest persistence and all target attempts.

- [ ] **Step 5: Run focused tests and verify GREEN**

Run both Task 1 and Task 2 test files and expect all tests to pass.

---

### Task 3: Regression And Live-Safe Verification

**Files:**
- Modify only if a regression test exposes a scoped issue in the files from Tasks 1-2.

**Interfaces:**
- Consumes: completed reconciler and job orchestration.
- Produces: verification evidence; no Shopify write is made during automated tests.

- [ ] **Step 1: Run the BEM regression suite**

```bash
TELESCOPE_ENABLED=false XDG_CONFIG_HOME=/tmp php artisan test tests/Feature/BemTargetMediaReconcilerTest.php tests/Feature/BemWatermarkUpdateManifestTest.php tests/Feature/BemWatermarkFlowTest.php
```

Expected: all tests pass.

- [ ] **Step 2: Run syntax and whitespace checks**

```bash
php -l app/Services/Shopify/BemWatermark/BemTargetMediaReconciler.php
php -l app/Jobs/BemSyncBackupManifestFromSourceUpdate.php
git diff --check
```

Expected: exit code `0` for each command.

- [ ] **Step 3: Restart the existing queue worker safely**

Run Laravel queue restart only after tests pass, using the application's configured cache path. Verify the existing PM2 worker remains online. Do not start an additional general queue worker.

- [ ] **Step 4: Verify the known incident in dry-run/read-only mode**

Confirm source `10905972572506`, backup `15128600904052`, and target `10673083646291` remain resolvable. Do not trigger a product update or media write as part of verification unless the user separately requests the repair.
