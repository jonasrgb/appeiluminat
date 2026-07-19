# Mixed Variant Replacement Design

## Scope

Allow one replication update to create missing target variants and delete stale
target variants. Variant identity remains based exclusively on
`custom.parentvariant`.

## Safety Conditions

The mixed replacement is allowed only when:

- every target variant has a unique `custom.parentvariant`;
- every source variant has a stable Shopify variant ID;
- all newly created variants return a deterministic target GID carrying the
  expected `custom.parentvariant`.

If identity validation fails, the job stops before structural writes. No SKU,
title, handle, or option-value fallback is permitted.

## Update Order

1. Resolve the target product only through `custom.parentproduct`.
2. Index target variants only through `custom.parentvariant`.
3. When option names match, create missing variants, validate them, then delete
   stale variants through the existing bulk mutations.
4. When option structure differs, call synchronous `productSet` with the complete
   desired option and variant list. Retained variants carry their existing GIDs;
   new variants carry `custom.parentvariant`; stale variants are omitted.
5. Validate that the response contains exactly one target variant for every
   source parent ID before updating local mirrors.
6. Reconcile option structure and update inventory fields.

The same-structure path creates first to satisfy Shopify's mandatory-one-variant
rule. The different-structure path uses Shopify's declarative operation so option
and variant list changes succeed or fail together.

## Verification

- Contract tests prove the blanket mixed-replacement guard is removed.
- Unit tests prove equal option structures use the existing ordered path and
  different structures use only the declarative `productSet` path.
- The existing DRAFT E2E product is retriggered from source variant `Verde` while
  targets still contain `Alb`.
- Final live checks require matching parent IDs, prices, option values, images,
  DRAFT status, and zero publications on all stores.
