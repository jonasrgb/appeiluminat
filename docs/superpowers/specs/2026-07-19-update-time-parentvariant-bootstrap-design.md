# Update-Time Parentvariant Bootstrap Design

## Context

Strict replication identifies target products only through
`custom.parentproduct` and target variants only through
`custom.parentvariant`. This prevents mutable fields such as SKU, title,
handle, and option values from linking an update to the wrong Shopify object.

Some legacy target products have a valid and unique `custom.parentproduct`,
but their old Shopify variant still has no `custom.parentvariant`. Shopify
represents a product with no explicit options as one `Default Title` variant,
so these products are currently reported as having an unmanaged target
variant. The strict update job stops before changing options, variants, or
variant prices.

This design adds a narrowly scoped identity bootstrap during an ordinary
product update. It does not add a full-store backfill and does not weaken the
strict parent-ID identity rules.

## Goals

- Repair a legacy target variant identity while processing the source product
  update that exposed the missing mapping.
- Preserve an existing target variant when the source and target each have
  exactly one variant.
- Rebuild the target variant structure once when the source has multiple
  variants and the target contains only one unmanaged default variant.
- Continue the existing strict variant synchronization immediately after a
  successful bootstrap.
- Apply the behavior consistently to Lustreled, Powerled, eIluminat Backup,
  and Iluminat Industrial.
- Remain idempotent: after successful bootstrap, future updates follow the
  normal `parentvariant` path and do not rebuild variants again.

## Non-goals

- No matching by SKU, title, handle, option names, option values, variant
  position, price, or local mirror data.
- No automatic repair when a target product is missing or ambiguous by
  `custom.parentproduct`.
- No automatic repair of a target containing multiple unmanaged variants.
- No automatic repair of a mixed target containing both managed and unmanaged
  variants.
- No full-store scan or bulk backfill.
- No changes to BEM watermark generation, image history, staged uploads,
  manifests, or media reconciliation.
- No changes to the Bulgaria stock-and-images-only workflow.

## Scope

The bootstrap runs from the shared full-replication update path for:

- `lustreled.myshopify.com`
- `powerleds-ro.myshopify.com`
- `eiluminatbackup.myshopify.com`
- `iluminat-industrial.myshopify.com`

`eiluminat-bg.myshopify.com` remains outside this behavior because it uses a
different stock-and-images synchronization contract.

## Preconditions

Bootstrap is considered only after all of the following checks pass:

1. The source payload contains a complete variant list and every source
   variant has a stable positive Shopify legacy ID.
2. The target product was resolved uniquely through
   `custom.parentproduct = <source product ID>`.
3. The target has no duplicate `custom.parentvariant` values.
4. The target contains exactly one live variant.
5. That single target variant has no valid `custom.parentvariant`.
6. The target contains no managed variants.

If any condition fails, the existing strict guard remains in force and no
bootstrap write is attempted.

The one-source/one-target variant count is used only to determine whether a
known target product can be initialized safely. It is not a cross-product
identity fallback: product identity was already established exclusively by
`custom.parentproduct`.

## Bootstrap Cases

### One Source Variant and One Unmanaged Target Variant

The existing target variant is preserved.

1. Set `custom.parentvariant` on the existing target variant to the single
   source variant legacy ID.
2. Re-fetch target variant identity state from Shopify.
3. Require exactly one target variant carrying the expected parent ID and no
   unmanaged or ambiguous variants.
4. Upsert the corresponding `VariantMirror` using the target GID returned by
   Shopify.
5. Continue the normal strict synchronization. This step may rename
   `Default Title`, update options, and synchronize mutable variant fields.

The metafield write must succeed before any local mirror is changed.

### Multiple Source Variants and One Unmanaged Target Variant

There is no deterministic source variant that can be assigned to the old
target default variant without using forbidden mutable fields. The target
variant structure is therefore replaced once through synchronous
`productSet`.

1. Build the complete desired option and variant structure from the source
   payload.
2. Include `custom.parentvariant` in every desired target variant input.
3. Do not retain or identify the old default variant by SKU, title, option
   value, or position.
4. Submit the complete structure through one declarative `productSet`
   operation.
5. Validate the response before writing local state:
   - every source variant ID appears exactly once;
   - every returned target variant has the expected `parentvariant`;
   - no unmanaged target variants remain;
   - no duplicate parent IDs exist;
   - the set of returned parent IDs exactly equals the source variant ID set.
6. Replace local `VariantMirror` rows for the product from the verified
   Shopify response.
7. Continue normal strict synchronization for mutable fields and inventory.

This replacement is a legacy bootstrap only. On later updates, the target is
fully managed and the existing create/update/delete diff runs by parent IDs.

## Unsafe States

The update remains fail-closed when any of these states is observed:

- more than one target variant lacks `parentvariant`;
- managed and unmanaged variants coexist on the target;
- duplicate target variants carry the same `parentvariant`;
- the source variant list is empty, truncated, or contains invalid IDs;
- the target product is missing or ambiguous by `parentproduct`;
- Shopify returns an incomplete or contradictory identity state after a
  bootstrap mutation.

Identity-state failures are logged with source product ID, target shop,
target product GID, source variant IDs, managed parent IDs, unmanaged target
GIDs, and ambiguous parent IDs. No mutable-field fallback is attempted.

## Error Handling and Idempotency

- A validation failure before writes skips variant synchronization for that
  target and leaves Shopify unchanged.
- A Shopify transport or mutation failure throws and follows the existing
  queue retry policy.
- Local mirrors are written only after Shopify identity is re-read and fully
  verified.
- A retry after a successful one-to-one metafield write observes a managed
  variant and enters normal strict synchronization.
- A retry after a successful declarative replacement observes the complete
  parent-ID set and does not replace the structure again.
- A contradictory post-mutation response is treated as an error; it must not
  be accepted into local mirrors.

## Code Boundaries

The recovery logic should be isolated from mutable variant synchronization.
A focused bootstrap component receives the source variant map, target shop,
and uniquely resolved target product GID, and returns one of:

- `not_needed`: the target is already fully managed;
- `bootstrapped`: Shopify identity was repaired and verified;
- `unsafe`: the target state is not eligible for automatic repair.

`ReplicateProductUpdateToShop::syncVariantsStrict()` invokes this component
immediately before the current unmanaged-variant guard. A `not_needed` or
verified `bootstrapped` result proceeds through the existing strict flow. An
`unsafe` result preserves the existing skip behavior.

The component may reuse the existing declarative variant-structure mutation
and parentvariant mutation helpers, but identity verification remains in
`ShopifyParentIdentityResolver` so one implementation defines valid target
state.

## Logging

Successful actions use explicit structured events:

- `Variant identity bootstrapped on existing target variant`
- `Variant identity structure bootstrapped declaratively`

Unsafe states retain a warning and include a stable reason code, such as:

- `legacy_variant_bootstrap_multiple_unmanaged`
- `legacy_variant_bootstrap_mixed_identity_state`
- `legacy_variant_bootstrap_ambiguous_parentvariant`
- `legacy_variant_bootstrap_source_payload_invalid`
- `legacy_variant_bootstrap_postcondition_failed`

Routine successful updates must not emit repeated bootstrap messages after
the initial repair.

## Tests

Automated tests must cover:

1. One source variant and one unmanaged target variant: the existing target
   GID is retained, parentvariant is assigned, and strict synchronization
   continues.
2. The one-to-one bootstrap does not inspect SKU, title, handle, option value,
   price, or variant position.
3. Multiple source variants and one unmanaged target variant: synchronous
   `productSet` receives the complete option/variant structure and every
   variant carries its source parent ID.
4. A successful declarative response rebuilds `VariantMirror` rows only from
   verified parent IDs.
5. A second identical update performs no bootstrap mutation.
6. Multiple unmanaged target variants are left unchanged.
7. A mixed managed/unmanaged target is left unchanged.
8. Duplicate parentvariant values are left unchanged.
9. Missing or ambiguous parentproduct prevents bootstrap.
10. Invalid or truncated source variants prevent bootstrap.
11. A failed Shopify mutation does not alter local mirrors and is retryable.
12. An invalid post-mutation identity response is rejected.
13. Existing strict create, update, mixed replacement, media, and BEM tests
    remain green.
14. Scope tests prove the behavior runs for Lustreled, Powerled, Backup, and
    Industrial, but not for Bulgaria.

## Live Verification

Use a DRAFT, unpublished source product and verify both recovery cases without
publishing to any sales channel:

1. One source variant versus one target `Default Title` variant without
   parentvariant.
2. Multiple source variants versus one target `Default Title` variant without
   parentvariant.

For each full-replication target, verify title, handle, options, source-to-target
parent IDs, prices, variant count, DRAFT status, and zero publications. Trigger
the same update a second time and confirm target variant GIDs remain stable and
no bootstrap event repeats.

## Rollout

1. Add failing tests for eligible and unsafe legacy states.
2. Implement the isolated bootstrap component and integrate it before the
   strict unmanaged-variant guard.
3. Run targeted identity and replication tests, followed by the affected BEM
   regression tests.
4. Restart queue workers so active workers load the new behavior.
5. Validate one DRAFT unpublished product across the four full-replication
   targets.
6. Monitor bootstrap and unsafe-state reason codes before relying on the
   behavior for active products.
