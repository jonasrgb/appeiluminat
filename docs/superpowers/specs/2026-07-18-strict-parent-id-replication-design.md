# Strict Parent ID Replication Design

## Context

Products are duplicated in the source Shopify store and may initially have no SKU. Staff can later change the title, handle, options, SKU, and price. Those mutable fields are therefore not reliable identities. Existing automatic fallback matching by handle, SKU, option values, or the number of variants can associate an update with the wrong target product or variant.

The target stores already define these identity metafields:

- Product: `custom.parentproduct`, containing the source product legacy ID.
- Variant: `custom.parentvariant`, containing the source variant legacy ID.

## Goals

- Use only `custom.parentproduct` to resolve an existing target product during automatic updates.
- Use only `custom.parentvariant` to resolve an existing target variant during automatic updates.
- Treat title, handle, SKU, and option values only as synchronized data, never as identity.
- Keep the BEM watermark workflow unchanged except for removing handle/SKU product discovery.
- Make missing or duplicate parent IDs visible in logs without guessing a match.
- Preserve deterministic create behavior by assigning parent IDs from the source and target IDs returned by Shopify.

## Non-goals

- No automatic backfill for existing products or variants without parent IDs.
- No full-store scan or migration in this change.
- No redesign of image processing, watermark generation, backup manifests, staged uploads, retries, or `prod.watermarked`.
- No replacement of the existing product-parent dashboard or backfill command. A separate explicit ID correlation workflow will be designed later.
- The Bulgaria stock-only flow is outside this change because it intentionally follows a different synchronization contract.

## Identity Rules

### Products

For every automatic target update:

1. Resolve products by `custom.parentproduct = <source product ID>`.
2. Accept exactly one target product.
3. A local `ProductMirror` is a cache, not an identity authority. Before using it, verify that its Shopify target product still contains the expected `custom.parentproduct` value.
4. If a cached mirror is missing or invalid, query Shopify only by `custom.parentproduct`.
5. If no match exists, skip that target update and log `missing_parentproduct_mapping`.
6. If multiple matches exist, skip that target update and log `ambiguous_parentproduct_mapping` with all candidate IDs.
7. Never fall back to handle, title, or SKU.

### Variants

For every automatic variant update:

1. Build the target map exclusively from `custom.parentvariant` values.
2. Match each source variant legacy ID to exactly one target variant.
3. A local `VariantMirror` is a cache only. Its target variant must still exist and expose the expected `custom.parentvariant` value before it can be used.
4. If no parentvariant match exists, leave that target variant untouched and log `missing_parentvariant_mapping`.
5. If multiple target variants contain the same parentvariant value, do not update or delete either one; log `ambiguous_parentvariant_mapping`.
6. Never fall back to SKU, title, selected options, canonical option keys, or the one-source/one-target variant count.

## Create Flow

Create operations do not discover an existing product by mutable data.

1. The duplicate guard checks only `custom.parentproduct` plus a validated local mirror created from an earlier deterministic create result.
2. The new target product receives `custom.parentproduct` from the source product ID.
3. Every target variant receives `custom.parentvariant` from the corresponding source variant ID using the target IDs returned by Shopify's create operations.
4. `ProductMirror` and `VariantMirror` rows are written only from those deterministic create results.
5. Parent metafield write failures are not silently accepted. They must fail or release the job for retry.
6. On retry after a partial create, a known local target ID is used only to repair and verify the missing parent metafields; it must not trigger another product creation.

Where Shopify supports metafields in the create input, parent IDs should be included in the same mutation. Where a separate mutation is required, the returned target ID is retained before the parent metafield write so retry remains idempotent.

## Update Flow

1. Resolve and verify the product by parentproduct.
2. Synchronize mutable product fields, including title and handle, onto that resolved product.
3. Fetch target variants and index only valid parentvariant values.
4. Update price, compare-at price, SKU, barcode, taxability, inventory policy, weight, and inventory only through that ID map.
5. If a new source variant has no target parentvariant match, create a new target variant and assign its parentvariant from the returned Shopify ID.
6. If a source variant is removed, delete only a target variant whose parentvariant points to that removed source variant.
7. Target variants without parentvariant are unmanaged: they are neither modified nor deleted by the automatic update.
8. When Shopify option mutations replace a target variant ID, the operation must preserve the known source variant ID and immediately assign it to the replacement variant. It must not rediscover the replacement by SKU, title, or option values.

## BEM Boundary

The BEM behavior remains unchanged except for product resolution.

The only BEM changes are:

- Remove target discovery by handle.
- Remove target discovery by SKU.
- Resolve a missing target mirror only through `custom.parentproduct`.
- Verify an existing target mirror against the Shopify `custom.parentproduct` value before use.
- Skip and log the target when no unique parentproduct match exists.

The following BEM behavior is explicitly preserved:

- Clean source image history and backup-store originals.
- Watermark image processing and double-watermark protection.
- Backup manifest reconciliation.
- Image ordering.
- Shopify staged uploads.
- `prod.watermarked` payload generation and updates.
- Retry, repair, and timeout behavior.
- Existing create/update watermark dispatch rules.

## Error Handling

Identity failures are fail-closed: no mutable-field inference is attempted.

Each skipped update logs the source shop, source product ID, target shop, expected parent ID, reason, and any conflicting target IDs. A missing mapping is not a queue failure because retries cannot create the missing correlation. Transient Shopify/API failures retain the existing retry behavior.

Create-time parent metafield failures are queue failures because the operation owns the deterministic mapping and can repair it safely on retry.

## Tests

Automated tests must cover:

- Product update succeeds after title, handle, and SKU changes when parentproduct is correct.
- Product update does not query or match by handle/SKU when parentproduct is absent.
- A stale ProductMirror is rejected when Shopify parentproduct differs.
- Duplicate parentproduct values stop the target update.
- Variant update succeeds only through parentvariant.
- Missing parentvariant leaves the existing target variant unchanged.
- Duplicate parentvariant values stop writes for the ambiguous source variant.
- One source and one target variant are not automatically paired without parentvariant.
- New source variants are created and assigned parentvariant deterministically.
- Deleted source variants delete only explicitly mapped target variants.
- Create retries repair parent metafields without creating a duplicate product.
- BEM resolves by parentproduct and performs no handle/SKU fallback.
- Existing BEM watermark processing tests remain unchanged and pass.

## Rollout

1. Add focused tests before changing production behavior.
2. Replace product and variant fallback matching in the active replication update path.
3. Restrict BEM bootstrap discovery to parentproduct without changing image behavior.
4. Harden deterministic parent metafield assignment in the create path.
5. Run targeted replication and BEM test suites.
6. Restart the queue workers after deployment so no worker retains the previous matching logic.
7. Validate one newly created draft product and one existing correctly correlated product before beginning the separate legacy correlation workflow.
