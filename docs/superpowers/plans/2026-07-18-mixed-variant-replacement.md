# Mixed Variant Replacement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replicate a source update that simultaneously adds and removes variants, including when the source option structure changes.

**Architecture:** Keep strict `parentproduct` and `parentvariant` identity resolution. Compatible option structures use the existing create-validate-delete sequence. Changed option structures use synchronous `productSet` with the complete desired option and variant lists; retained variants use target GIDs already verified through `parentvariant`, new variants receive `parentvariant` atomically, and stale variants are omitted.

**Tech Stack:** Laravel 10, PHP 8, Shopify Admin GraphQL API `2025-01`, PHPUnit.

## Global Constraints

- Product identity uses only `custom.parentproduct`.
- Variant identity uses only `custom.parentvariant`.
- No SKU, title, handle, or option-value identity fallback.
- Create and validate missing variants before deleting stale variants when the option structure is unchanged.
- Validate the complete `productSet` response before changing local mirrors when the option structure changes.
- Keep the test products DRAFT and unpublished.

---

### Task 1: Compatible Mixed-Replacement Gate

**Files:**
- Modify: `tests/Unit/ReplicateProductUpdateIdentityContractTest.php`
- Modify: `app/Jobs/ReplicateProductUpdateToShop.php`

**Interfaces:**
- Consumes: normalized source options and Shopify target option nodes.
- Produces: `mixedReplacementHasCompatibleOptions(array $sourceOptions, array $targetOptions): bool`.

- [ ] **Step 1: Write failing tests**

Add tests proving that matching canonical option names in the same order are accepted, mismatched names/order are rejected, and `syncVariantsStrict()` no longer contains the blanket `variant_set_replacement_requires_manual_resolution` return.

For mismatched option structures, add a second TDD task that sends the complete
desired option and variant lists through synchronous `productSet`. Existing
variants are identified only by target GID already verified through
`custom.parentvariant`; new variants include `custom.parentvariant` atomically;
the response must contain exactly the source parent ID set before local mirrors
are changed.

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```bash
TELESCOPE_ENABLED=false XDG_CONFIG_HOME=/tmp php artisan test tests/Unit/ReplicateProductUpdateIdentityContractTest.php --filter=mixed_replacement
```

Expected: failure because the compatibility method does not exist and the blanket guard is still present.

- [x] **Step 3: Implement the compatibility gate and declarative replacement**

Canonicalize source and target option names with `canonOptName()`. When the ordered lists differ, send the complete desired structure through synchronous `productSet` and require the exact source `parentvariant` set in Shopify's response before updating local mirrors.

- [x] **Step 4: Run focused and related tests**

Run:

```bash
TELESCOPE_ENABLED=false XDG_CONFIG_HOME=/tmp php artisan test tests/Unit/ReplicateProductUpdateIdentityContractTest.php tests/Unit/ReplicateProductCreateIdentityContractTest.php tests/Feature/ShopifyParentIdentityResolverTest.php tests/Unit/BemBootstrapIdentityContractTest.php
```

Expected: all tests pass.

### Task 2: Live DRAFT Verification

**Files:**
- No production file changes.

**Interfaces:**
- Consumes: source product `10934827811162` and its three existing target products.
- Produces: verified target variants linked to source variant `54442437443930`.

- [x] **Step 1: Restart the Laravel queue worker**

Run `pm2 restart laravel-queue` and confirm a new online PID.

- [x] **Step 2: Trigger the existing final source state once**

Set `dont.trigger2=true` on source product `10934827811162` without calling any publication mutation.

- [x] **Step 3: Verify Shopify state**

Require all three target products to contain only `Verde`, price `379.90`, and `custom.parentvariant=54442437443930`. Require three READY images, DRAFT status, and zero publications on source and targets.

- [x] **Step 4: Run final checks**

Run `git diff --check`, PHP syntax validation, and the full related test suite. Expected: zero failures.
