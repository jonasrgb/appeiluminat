# Strict Parent ID Replication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make automatic Shopify product and variant replication resolve identity exclusively through `custom.parentproduct` and `custom.parentvariant`, while preserving the BEM watermark pipeline apart from its target-product lookup.

**Architecture:** Add one focused Shopify identity resolver that validates cached product IDs and indexes target variants by exact parent metafield values. The update job consumes that verified state and treats local mirrors as caches only; the BEM bootstrap consumes the same product resolver without changing image processing. The create job remains deterministic and is hardened so parent metafield failures retry instead of leaving an unidentifiable product.

**Tech Stack:** Laravel queue jobs, Eloquent, Laravel HTTP client, Shopify Admin GraphQL API `2025-01`, PHPUnit with isolated SQLite databases and `Http::fake()`.

## Global Constraints

- Product identity is only `custom.parentproduct`, containing the source product legacy ID.
- Variant identity is only `custom.parentvariant`, containing the source variant legacy ID.
- Title, handle, SKU, option values, canonical option keys, and variant count are synchronized data, never identity.
- No automatic backfill or full-store scan is part of this change.
- BEM image history, backup manifests, watermark processing, staged upload, ordering, `prod.watermarked`, repair, and retry behavior must remain unchanged.
- Target variants without `custom.parentvariant` are unmanaged and must not be modified or deleted automatically.
- The Bulgaria stock-only flow remains outside this change.

---

## File Map

- Create `app/Services/Shopify/ShopifyParentIdentityResolver.php`: the only new identity boundary; validates or discovers products by `custom.parentproduct` and indexes variants by `custom.parentvariant`.
- Create `tests/Feature/ShopifyParentIdentityResolverTest.php`: isolated tests for found, missing, stale-cache, and ambiguous parent IDs.
- Modify `app/Jobs/ReplicateProductUpdateToShop.php`: replace product bootstrap and variant option/SKU inference with resolver output; retain mutable-field synchronization.
- Create `tests/Feature/ReplicateProductStrictIdentityTest.php`: job-level regression tests proving mutable fields are not used as identity.
- Modify `app/Services/Shopify/BemWatermark/BemWatermarkUpdateBootstrapService.php`: use strict product resolution while leaving all image code untouched.
- Modify `tests/Feature/BemWatermarkUpdateBootstrapTest.php`: verify BEM validates cached mirrors and never searches by handle/SKU.
- Modify `app/Jobs/ReplicateProductCreateToShop.php`: make parent metafield assignment deterministic, verified, and retryable.
- Create `tests/Feature/ReplicateProductCreateParentIdentityTest.php`: verify create and partial-create retry behavior.

### Task 1: Shared Shopify Parent Identity Resolver

**Files:**
- Create: `app/Services/Shopify/ShopifyParentIdentityResolver.php`
- Create: `tests/Feature/ShopifyParentIdentityResolverTest.php`

**Interfaces:**
- Consumes: `App\Models\Shop` with `domain`, `access_token`, and `api_version`.
- Produces: `resolveProduct(Shop $shop, int $sourceProductId, ?string $expectedTargetGid = null): array`.
- Produces: `targetVariantState(Shop $shop, string $productGid): array`.
- Product result shape: `['status' => 'found'|'missing'|'ambiguous', 'product' => ?array, 'candidates' => array]`.
- Variant result shape: `['nodes_by_gid' => array, 'by_parent_id' => array, 'ambiguous_parent_ids' => array, 'unmanaged_gids' => array]`.

- [ ] **Step 1: Write failing resolver tests**

Create an isolated SQLite test fixture with a `shops` table, then cover exact identity behavior:

```php
public function test_it_rejects_a_stale_expected_gid_and_finds_the_exact_parentproduct(): void
{
    $shop = $this->shop();

    Http::fake(function ($request) {
        $body = $request->data();
        $query = (string) ($body['query'] ?? '');

        if (str_contains($query, 'ParentIdentityProductById')) {
            return Http::response(['data' => ['product' => [
                'id' => 'gid://shopify/Product/900',
                'legacyResourceId' => '900',
                'metafield' => ['value' => '111'],
            ]]]);
        }

        return Http::response(['data' => ['products' => ['nodes' => [[
            'id' => 'gid://shopify/Product/901',
            'legacyResourceId' => '901',
            'metafield' => ['value' => '222'],
        ]]]]]);
    });

    $result = app(ShopifyParentIdentityResolver::class)->resolveProduct(
        $shop,
        222,
        'gid://shopify/Product/900'
    );

    $this->assertSame('found', $result['status']);
    $this->assertSame('gid://shopify/Product/901', $result['product']['id']);
}

public function test_it_reports_duplicate_parentproduct_values_as_ambiguous(): void
{
    Http::fake(Http::response(['data' => ['products' => ['nodes' => [
        ['id' => 'gid://shopify/Product/901', 'legacyResourceId' => '901', 'metafield' => ['value' => '222']],
        ['id' => 'gid://shopify/Product/902', 'legacyResourceId' => '902', 'metafield' => ['value' => '222']],
    ]]]]));

    $result = app(ShopifyParentIdentityResolver::class)->resolveProduct($this->shop(), 222);

    $this->assertSame('ambiguous', $result['status']);
    $this->assertCount(2, $result['candidates']);
}

public function test_variant_state_indexes_only_exact_parentvariant_values(): void
{
    Http::fake(Http::response(['data' => ['product' => ['variants' => ['nodes' => [
        ['id' => 'gid://shopify/ProductVariant/10', 'legacyResourceId' => '10', 'metafield' => ['value' => '501']],
        ['id' => 'gid://shopify/ProductVariant/11', 'legacyResourceId' => '11', 'metafield' => null],
        ['id' => 'gid://shopify/ProductVariant/12', 'legacyResourceId' => '12', 'metafield' => ['value' => '501']],
    ]]]]]));

    $state = app(ShopifyParentIdentityResolver::class)->targetVariantState(
        $this->shop(),
        'gid://shopify/Product/901'
    );

    $this->assertArrayNotHasKey('501', $state['by_parent_id']);
    $this->assertCount(2, $state['ambiguous_parent_ids']['501']);
    $this->assertSame(['gid://shopify/ProductVariant/11'], $state['unmanaged_gids']);
}
```

- [ ] **Step 2: Run the focused tests and verify failure**

Run:

```bash
TELESCOPE_ENABLED=false XDG_CONFIG_HOME=/tmp php artisan test tests/Feature/ShopifyParentIdentityResolverTest.php
```

Expected: FAIL because `ShopifyParentIdentityResolver` does not exist.

- [ ] **Step 3: Implement strict product resolution**

Create the service with no handle/SKU parameters or query paths:

```php
final class ShopifyParentIdentityResolver
{
    public function resolveProduct(Shop $shop, int $sourceProductId, ?string $expectedTargetGid = null): array
    {
        if ($expectedTargetGid) {
            $expected = $this->fetchProduct($shop, $expectedTargetGid);
            if ((string) data_get($expected, 'metafield.value') === (string) $sourceProductId) {
                return ['status' => 'found', 'product' => $expected, 'candidates' => [$expected]];
            }
        }

        $products = $this->searchByParentProduct($shop, $sourceProductId);
        $matches = array_values(array_filter(
            $products,
            static fn (array $product): bool =>
                (string) data_get($product, 'metafield.value') === (string) $sourceProductId
        ));

        return match (count($matches)) {
            0 => ['status' => 'missing', 'product' => null, 'candidates' => []],
            1 => ['status' => 'found', 'product' => $matches[0], 'candidates' => $matches],
            default => ['status' => 'ambiguous', 'product' => null, 'candidates' => $matches],
        };
    }
}
```

Use named GraphQL operations `ParentIdentityProductById`, `ParentIdentityProductSearch`, and `ParentIdentityVariants`. Product search must be exactly:

```php
$queryString = 'metafields.custom.parentproduct:'.$this->quoteSearchValue((string) $sourceProductId);
```

The product queries must request `id`, `legacyResourceId`, `title`, `handle`, and:

```graphql
metafield(namespace: "custom", key: "parentproduct") { value }
```

The variant query must paginate until `hasNextPage` is false and request:

```graphql
variants(first: 100, after: $after) {
  nodes {
    id
    legacyResourceId
    metafield(namespace: "custom", key: "parentvariant") { value }
  }
  pageInfo { hasNextPage endCursor }
}
```

Build `by_parent_id` only for unique, non-empty integer values. Put duplicate values in `ambiguous_parent_ids` and variants with empty values in `unmanaged_gids`.

- [ ] **Step 4: Run resolver tests**

Run the command from Step 2.

Expected: PASS; no request variable contains `handle:` or `sku:`.

- [ ] **Step 5: Commit the resolver**

```bash
git add app/Services/Shopify/ShopifyParentIdentityResolver.php tests/Feature/ShopifyParentIdentityResolverTest.php
git commit -m "feat: add strict Shopify parent identity resolver"
```

### Task 2: Strict Product Resolution in Automatic Updates

**Files:**
- Modify: `app/Jobs/ReplicateProductUpdateToShop.php:53-291`
- Test: `tests/Feature/ReplicateProductStrictIdentityTest.php`

**Interfaces:**
- Consumes: `ShopifyParentIdentityResolver::resolveProduct()` from Task 1.
- Produces: a verified `ProductMirror` or a fail-closed return before any Shopify mutation.

- [ ] **Step 1: Add job tests for product identity**

Create tests with isolated `shops`, `product_mirrors`, and `variant_mirrors` tables and `Http::fake()` request capture:

```php
public function test_update_uses_parentproduct_even_when_title_handle_and_sku_changed(): void
{
    [$source, $target] = $this->shops();
    $this->fakeResolvedProduct($target, 700, 'gid://shopify/Product/900');

    (new ReplicateProductUpdateToShop(
        targetShopId: $target->id,
        sourceShopId: $source->id,
        sourceProductId: 700,
        payload: $this->payload(title: 'Titlu nou', handle: 'handle-nou', sku: '')
    ))->handle(app(ShopifyParentIdentityResolver::class));

    $this->assertDatabaseHas('product_mirrors', [
        'source_product_id' => 700,
        'target_product_gid' => 'gid://shopify/Product/900',
    ]);
    $this->assertMutationTargeted('gid://shopify/Product/900');
}

public function test_update_stops_before_mutation_when_parentproduct_is_missing(): void
{
    $this->fakeMissingParentProduct();
    $this->runJobWithPayload(handle: 'same-handle', sku: 'same-sku');

    Http::assertSent(fn ($request) => !str_contains(json_encode($request->data()), 'handle:same-handle'));
    Http::assertSent(fn ($request) => !str_contains(json_encode($request->data()), 'sku:same-sku'));
    $this->assertNoMutationWasSent();
}
```

Add cases for a stale local mirror and duplicate parentproduct. In both cases, verify no product update, option mutation, variant mutation, metafield mutation, image mutation, or inventory mutation is sent.

- [ ] **Step 2: Run the product identity tests and verify failure**

```bash
TELESCOPE_ENABLED=false XDG_CONFIG_HOME=/tmp php artisan test tests/Feature/ReplicateProductStrictIdentityTest.php --filter=product
```

Expected: FAIL because `handle()` has no resolver parameter and still performs handle/SKU fallback.

- [ ] **Step 3: Replace the update bootstrap block**

Change the job signature and resolve every target before mutable writes:

```php
public function handle(ShopifyParentIdentityResolver $identityResolver): void
{
    // Existing source-deletion and BG guards remain first.
    $target = Shop::findOrFail($this->targetShopId);
    $cached = ProductMirror::where([
        'source_shop_id' => $this->sourceShopId,
        'target_shop_id' => $this->targetShopId,
        'source_product_id' => $this->sourceProductId,
    ])->first();

    $resolution = $identityResolver->resolveProduct(
        $target,
        $this->sourceProductId,
        $cached?->target_product_gid
    );

    if ($resolution['status'] !== 'found') {
        Log::warning('ReplicateProductUpdate skipped: strict parentproduct mapping unavailable', [
            'reason' => $resolution['status'] === 'ambiguous'
                ? 'ambiguous_parentproduct_mapping'
                : 'missing_parentproduct_mapping',
            'source_shop_id' => $this->sourceShopId,
            'source_product_id' => $this->sourceProductId,
            'target_shop_id' => $target->id,
            'target_shop' => $target->domain,
            'candidate_gids' => array_column($resolution['candidates'], 'id'),
        ]);
        return;
    }

    $product = $resolution['product'];
    $mirror = ProductMirror::updateOrCreate(
        [
            'source_shop_id' => $this->sourceShopId,
            'target_shop_id' => $target->id,
            'source_product_id' => $this->sourceProductId,
        ],
        [
            'source_product_gid' => 'gid://shopify/Product/'.$this->sourceProductId,
            'target_product_gid' => $product['id'],
            'target_product_id' => (int) ($product['legacyResourceId'] ?? $this->numericIdFromGid($product['id'])),
        ]
    );

    // Existing product/image/metafield synchronization continues here.
}
```

Delete the active calls and private methods for `searchTargetProductByHandle()` and `searchTargetProductBySkus()`. Remove `ProductParentBackfillCandidate` from this job. Do not gate strict resolution behind `features.mirror_bootstrap.enabled` or `dry_run`; a valid Shopify parentproduct is the authority.

- [ ] **Step 4: Run product identity tests**

Run the command from Step 2.

Expected: PASS; missing and ambiguous mappings emit logs but no mutations.

- [ ] **Step 5: Commit strict product resolution**

```bash
git add app/Jobs/ReplicateProductUpdateToShop.php tests/Feature/ReplicateProductStrictIdentityTest.php
git commit -m "fix: resolve product updates only by parentproduct"
```

### Task 3: Strict Variant Resolution and Mutation Guards

**Files:**
- Modify: `app/Jobs/ReplicateProductUpdateToShop.php:353-620`
- Modify: `app/Jobs/ReplicateProductUpdateToShop.php:2003-2156`
- Modify: `app/Jobs/ReplicateProductUpdateToShop.php:2362-2635`
- Test: `tests/Feature/ReplicateProductStrictIdentityTest.php`

**Interfaces:**
- Consumes: `ShopifyParentIdentityResolver::targetVariantState()` from Task 1.
- Produces: `ensureStrictVariantMirrors(...): array` returning the verified variant state and maps keyed by source variant ID.
- Produces: `computeStrictVariantDiff(...): array` with `toCreate`, `toUpdate`, and `toDelete`, all keyed by source variant ID.

- [ ] **Step 1: Write failing strict variant tests**

Add these cases:

```php
public function test_variant_price_updates_only_through_parentvariant(): void
{
    $this->fakeTargetVariants([
        $this->targetVariant(9001, parentVariant: 5001, sku: 'TARGET-OLD'),
    ]);

    $this->runJobWithVariants([
        $this->sourceVariant(5001, price: '199.00', sku: 'SOURCE-NEW'),
    ]);

    $this->assertVariantMutation('gid://shopify/ProductVariant/9001', '199.00');
}

public function test_single_variants_are_not_paired_without_parentvariant(): void
{
    $this->fakeTargetVariants([
        $this->targetVariant(9001, parentVariant: null, sku: 'SAME-SKU'),
    ]);

    $this->runJobWithVariants([
        $this->sourceVariant(5001, price: '199.00', sku: 'SAME-SKU'),
    ]);

    $this->assertNoVariantMutationFor('gid://shopify/ProductVariant/9001');
    $this->assertDatabaseMissing('variant_mirrors', ['source_variant_id' => 5001]);
}

public function test_duplicate_parentvariant_stops_writes_for_that_source_variant(): void
{
    $this->fakeTargetVariants([
        $this->targetVariant(9001, parentVariant: 5001),
        $this->targetVariant(9002, parentVariant: 5001),
    ]);

    $this->runJobWithVariants([$this->sourceVariant(5001, price: '199.00')]);

    $this->assertNoVariantMutationFor('gid://shopify/ProductVariant/9001');
    $this->assertNoVariantMutationFor('gid://shopify/ProductVariant/9002');
}
```

Also cover:

- a stale `VariantMirror` whose target now has another parentvariant;
- source variant deletion removes only a target variant with the deleted source ID in `parentvariant`;
- an unmanaged target variant survives source deletion;
- a new source variant is created only when all existing target variants are managed and receives `custom.parentvariant` immediately;
- option mutation and REST option fallback are skipped when `unmanaged_gids` is non-empty;
- option title/value changes do not change the source-to-target ID association.

- [ ] **Step 2: Run strict variant tests and verify failure**

```bash
TELESCOPE_ENABLED=false XDG_CONFIG_HOME=/tmp php artisan test tests/Feature/ReplicateProductStrictIdentityTest.php --filter=variant
```

Expected: FAIL because current code maps by option key and single-variant fallback.

- [ ] **Step 3: Replace variant-state fetching and mirror validation**

Inject the resolver already accepted by `handle()` and fetch target state once after product resolution:

```php
$targetVariantState = $identityResolver->targetVariantState(
    $target,
    $mirror->target_product_gid
);

$variantMirrors = $this->ensureStrictVariantMirrors(
    $mirror,
    $target,
    $srcVariants,
    $targetVariantState
);
```

The new method must use only source variant IDs:

```php
private function ensureStrictVariantMirrors(
    ProductMirror $mirror,
    Shop $target,
    array $sourceVariants,
    array $targetState
): array {
    $verified = [];

    foreach ($sourceVariants as $sourceVariant) {
        $sourceId = (int) ($sourceVariant['source_variant_id'] ?? 0);
        if ($sourceId <= 0 || isset($targetState['ambiguous_parent_ids'][(string) $sourceId])) {
            Log::warning('Variant update skipped: ambiguous or invalid parentvariant mapping', [
                'reason' => $sourceId > 0 ? 'ambiguous_parentvariant_mapping' : 'missing_source_variant_id',
                'source_product_id' => $this->sourceProductId,
                'source_variant_id' => $sourceId ?: null,
                'target_shop' => $target->domain,
            ]);
            continue;
        }

        $targetNode = $targetState['by_parent_id'][(string) $sourceId] ?? null;
        if (!$targetNode) {
            Log::warning('Variant update skipped: strict parentvariant mapping unavailable', [
                'reason' => 'missing_parentvariant_mapping',
                'source_product_id' => $this->sourceProductId,
                'source_variant_id' => $sourceId,
                'target_shop' => $target->domain,
            ]);
            continue;
        }

        $verified[$sourceId] = VariantMirror::updateOrCreate(
            ['product_mirror_id' => $mirror->id, 'source_variant_id' => $sourceId],
            [
                'source_options_key' => $sourceVariant['source_options_key'] ?? null,
                'target_variant_id' => (int) ($targetNode['legacyResourceId'] ?? $this->numericIdFromGid($targetNode['id'])),
                'target_variant_gid' => $targetNode['id'],
            ]
        );
    }

    return $verified;
}
```

Delete option-key lookup, single-variant fallback, `existing_mirror_gid` acceptance, and parentvariant writes from `ensureVariantMirrors()`. Parentvariant is written only after this job itself creates a new variant.

- [ ] **Step 4: Replace variant diff identity**

Normalize source variants into a map keyed by `source_variant_id`. Build:

```php
$toUpdate[$sourceId] = ['src' => $sourceVariant, 'mirror' => $verified[$sourceId]];
$toCreate[$sourceId] = $sourceVariant; // only when structural mutation is safe
$toDelete[$sourceId] = $targetNode;    // only parent IDs absent from source
```

Never build delete candidates from an option-key mirror remainder. Exclude all IDs in `ambiguous_parent_ids`, and never include `unmanaged_gids`.

When `unmanaged_gids` is non-empty:

```php
$allowStructuralVariantChanges = empty($targetVariantState['unmanaged_gids'])
    && empty($targetVariantState['ambiguous_parent_ids']);
```

Use that boolean to skip `productOptionsCreate`, `productOptionsSet`, REST option fallback, variant create, and variant delete. Continue economic, SKU/barcode, inventory, and weight updates for verified mapped variants only.

- [ ] **Step 5: Make new variant creation deterministic**

Change `productVariantsBulkCreateForUpdate()` to preserve request ordering and map the returned node at each index back to the source variant ID supplied at the same index. Return:

```php
[
    5002 => [
        'source_variant_id' => 5002,
        'variant_gid' => 'gid://shopify/ProductVariant/9002',
        'inventory_item_gid' => 'gid://shopify/InventoryItem/8002',
    ],
]
```

Immediately after each successful create, call:

```php
$this->setParentVariantMetafield($target, $newGid, $sourceId, 'deterministic_create');
```

Make `setParentVariantMetafield()` throw on top-level errors and `userErrors`; do not catch and convert these failures to warnings. Only after this succeeds, write the `VariantMirror`.

- [ ] **Step 6: Run strict variant tests**

Run the command from Step 2.

Expected: PASS; HTTP request inspection shows no identity query or mapping through SKU, title, selected option values, or single-variant count.

- [ ] **Step 7: Commit strict variant behavior**

```bash
git add app/Jobs/ReplicateProductUpdateToShop.php tests/Feature/ReplicateProductStrictIdentityTest.php
git commit -m "fix: resolve variant updates only by parentvariant"
```

### Task 4: Strict BEM Product Resolution

**Files:**
- Modify: `app/Services/Shopify/BemWatermark/BemWatermarkUpdateBootstrapService.php:14-76`
- Modify: `app/Services/Shopify/BemWatermark/BemWatermarkUpdateBootstrapService.php:257-493`
- Modify: `app/Services/Shopify/BemWatermark/BemWatermarkUpdateBootstrapService.php:561-599`
- Modify: `tests/Feature/BemWatermarkUpdateBootstrapTest.php`

**Interfaces:**
- Consumes: `ShopifyParentIdentityResolver::resolveProduct()` from Task 1.
- Produces: a validated BEM `ProductMirror` without changing downstream BEM image behavior.

- [ ] **Step 1: Write failing BEM identity tests**

Add tests that:

```php
public function test_bem_rejects_stale_mirror_and_uses_exact_parentproduct_candidate(): void
{
    // Existing mirror points to target 900 whose parentproduct is 111.
    // Search returns target 901 whose parentproduct is expected source 222.
    // Bootstrap must update the mirror to 901 before reading target images.
}

public function test_bem_does_not_fall_back_to_handle_or_sku(): void
{
    // Parentproduct search returns no products although source/target handle and SKU could match.
    // Bootstrap leaves the target unlinked.
    Http::assertSent(fn ($request) => !str_contains(
        json_encode($request->data()),
        'handle:'
    ));
    Http::assertSent(fn ($request) => !str_contains(
        json_encode($request->data()),
        'sku:'
    ));
}
```

Keep `test_legacy_bootstrap_reconciles_backup_from_current_clean_source_images()` unchanged except for fake responses needed by the new strict identity verification query.

- [ ] **Step 2: Run BEM bootstrap tests and verify failure**

```bash
TELESCOPE_ENABLED=false XDG_CONFIG_HOME=/tmp php artisan test tests/Feature/BemWatermarkUpdateBootstrapTest.php
```

Expected: new tests FAIL because existing mirrors are trusted and handle/SKU fallback remains active.

- [ ] **Step 3: Inject and use the strict resolver**

Change the constructor:

```php
public function __construct(
    private readonly BemShopifyGraphqlClient $graphql,
    private readonly BemWatermarkIdentityService $identity,
    private readonly BemShopifyStagedUploadService $stagedUpload,
    private readonly BemWatermarkEligibilityService $eligibility,
    private readonly ShopifyParentIdentityResolver $parentIdentity,
) {}
```

Remove `handle` and `skus` from `ensureMirror()` and `linkExistingTargetMirrors()`. Resolve with the cached GID when present:

```php
$resolution = $this->parentIdentity->resolveProduct(
    $target,
    $sourceProductId,
    $existing?->target_product_gid
);

if ($resolution['status'] !== 'found') {
    Log::warning('BEM update bootstrap skipped: strict parentproduct mapping unavailable', [
        'reason' => $resolution['status'] === 'ambiguous'
            ? 'ambiguous_parentproduct_mapping'
            : 'missing_parentproduct_mapping',
        'role' => $role,
        'target_shop' => $target->domain,
        'source_product_id' => $sourceProductId,
        'candidate_gids' => array_column($resolution['candidates'], 'id'),
    ]);
    return null;
}
```

Update or create the mirror from the verified product. Remove `ProductParentBackfillCandidate`, `findTargetProduct()`, and the BEM handle/SKU search branches. Keep source SKU fetching only if another BEM payload concern needs it; it must not participate in identity.

- [ ] **Step 4: Run BEM tests**

Run:

```bash
TELESCOPE_ENABLED=false XDG_CONFIG_HOME=/tmp php artisan test \
  tests/Feature/BemWatermarkUpdateBootstrapTest.php \
  tests/Feature/BemWatermarkUpdateManifestTest.php \
  tests/Feature/BemWatermarkFlowTest.php
```

Expected: PASS with existing image assertions unchanged.

- [ ] **Step 5: Commit BEM resolver change**

```bash
git add app/Services/Shopify/BemWatermark/BemWatermarkUpdateBootstrapService.php tests/Feature/BemWatermarkUpdateBootstrapTest.php
git commit -m "fix: restrict BEM target lookup to parentproduct"
```

### Task 5: Deterministic Create and Retry Hardening

**Files:**
- Modify: `app/Jobs/ReplicateProductCreateToShop.php:51-290`
- Modify: `app/Jobs/ReplicateProductCreateToShop.php:373-430`
- Modify: `app/Jobs/ReplicateProductCreateToShop.php:540-640`
- Modify: `app/Jobs/ReplicateProductCreateToShop.php:889-990`
- Modify: `app/Jobs/ReplicateProductCreateToShop.php:1618-1754`
- Create: `tests/Feature/ReplicateProductCreateParentIdentityTest.php`

**Interfaces:**
- Consumes: exact source product and variant IDs from the create webhook payload.
- Produces: target products and variants carrying parent metafields before the job is considered complete.

- [ ] **Step 1: Write failing create/retry tests**

Cover:

```php
public function test_create_assigns_product_and_variant_parent_ids_from_returned_shopify_ids(): void
{
    $this->fakeCreateProduct('gid://shopify/Product/900');
    $this->fakeCreatedVariants([
        'gid://shopify/ProductVariant/9001',
        'gid://shopify/ProductVariant/9002',
    ]);

    $this->runCreateJob(sourceProductId: 700, sourceVariantIds: [5001, 5002]);

    $this->assertParentMetafieldSet('gid://shopify/Product/900', 'parentproduct', '700');
    $this->assertParentMetafieldSet('gid://shopify/ProductVariant/9001', 'parentvariant', '5001');
    $this->assertParentMetafieldSet('gid://shopify/ProductVariant/9002', 'parentvariant', '5002');
}

public function test_retry_repairs_known_partial_create_without_creating_another_product(): void
{
    $this->seedMirror(targetProductGid: 'gid://shopify/Product/900');
    $this->fakeProductParentVerification(parentProduct: null);

    $this->runCreateJob(sourceProductId: 700, sourceVariantIds: [5001]);

    $this->assertSame(0, $this->countOperation('productCreate'));
    $this->assertParentMetafieldSet('gid://shopify/Product/900', 'parentproduct', '700');
}

public function test_parent_metafield_user_error_fails_the_create_job(): void
{
    $this->fakeParentMetafieldUserError('invalid value');
    $this->expectException(RuntimeException::class);
    $this->runCreateJob(sourceProductId: 700, sourceVariantIds: [5001]);
}
```

- [ ] **Step 2: Run create tests and verify failure**

```bash
TELESCOPE_ENABLED=false XDG_CONFIG_HOME=/tmp php artisan test tests/Feature/ReplicateProductCreateParentIdentityTest.php
```

Expected: FAIL because parent metafield setters currently swallow failures and existing mirrors are not fully repaired/verified.

- [ ] **Step 3: Make product parent assignment retryable**

Remove the `try/catch` that converts `metafieldsSet` failures to warnings. `setParentProductMetafield()` must throw on top-level GraphQL errors and user errors:

```php
if (!empty($response['errors'])) {
    throw new RuntimeException('Parentproduct metafield GraphQL errors: '.json_encode($response['errors']));
}

$userErrors = data_get($response, 'data.metafieldsSet.userErrors', []);
if ($userErrors) {
    throw new RuntimeException('Parentproduct metafield userErrors: '.json_encode($userErrors));
}
```

When a `ProductMirror` already contains a deterministic target GID, do not create another product. Set/repair `parentproduct`, verify the product exists, repair known variant parent metafields from deterministic `VariantMirror` rows, dispatch the unchanged BEM continuation, and return.

When a new product is created, persist the deterministic target product ID immediately after `productCreate()` returns and before the separate parent metafield call. This gives a retry a stable target even if parent assignment fails.

- [ ] **Step 4: Make variant assignment deterministic and retryable**

Preserve the source request order in `createAllOptionsAndVariants()` and pair Shopify’s returned variants by response index, not by selected-option lookup:

```php
foreach ($created as $index => $node) {
    $sourceVariant = array_values($src['variants'] ?? [])[$index] ?? null;
    if (!$sourceVariant || empty($sourceVariant['id']) || empty($node['id'])) {
        throw new RuntimeException('Unable to pair deterministic create variant at index '.$index);
    }

    $variantMap[] = [
        'source_variant_id' => (int) $sourceVariant['id'],
        'source_options_key' => $this->buildOptionsKeyFromSource($sourceVariant, $optionNames),
        'target_variant_gid' => $node['id'],
        'target_variant_id' => $this->legacyIdFromGid($node['id']),
        'inventory_item_gid' => data_get($node, 'inventoryItem.id'),
        'snapshot' => $this->variantSnapshot($sourceVariant),
    ];
}
```

Make `setParentVariantMetafield()` throw on errors. Persist each deterministic `VariantMirror` before the metafield call so retries know the returned target ID, then set and verify `custom.parentvariant`. Do not search by SKU, title, handle, or options during repair.

- [ ] **Step 5: Run create tests**

Run the command from Step 2.

Expected: PASS; retries send zero `productCreate` mutations.

- [ ] **Step 6: Commit create hardening**

```bash
git add app/Jobs/ReplicateProductCreateToShop.php tests/Feature/ReplicateProductCreateParentIdentityTest.php
git commit -m "fix: make create parent identity retryable"
```

### Task 6: Regression Verification and Live Worker Rollout

**Files:**
- Verify: `app/Jobs/ReplicateProductUpdateToShop.php`
- Verify: `app/Jobs/ReplicateProductCreateToShop.php`
- Verify: `app/Services/Shopify/BemWatermark/BemWatermarkUpdateBootstrapService.php`
- Verify: all tests added or modified above

**Interfaces:**
- Consumes: completed Tasks 1-5.
- Produces: verified application behavior and reloaded queue workers.

- [ ] **Step 1: Run static syntax checks**

```bash
php -l app/Services/Shopify/ShopifyParentIdentityResolver.php
php -l app/Jobs/ReplicateProductUpdateToShop.php
php -l app/Jobs/ReplicateProductCreateToShop.php
php -l app/Services/Shopify/BemWatermark/BemWatermarkUpdateBootstrapService.php
```

Expected: `No syntax errors detected` for every file.

- [ ] **Step 2: Prove forbidden automatic fallbacks are absent**

```bash
rg -n "searchTargetProductByHandle|searchTargetProductBySkus|matchedBy = 'options_key'|matchedBy = 'single_variant'|local_backfill_snapshot" \
  app/Jobs/ReplicateProductUpdateToShop.php \
  app/Services/Shopify/BemWatermark/BemWatermarkUpdateBootstrapService.php
```

Expected: no matches. Manual `ProductParentBackfillService` may still contain handle/SKU matching because it is outside the automatic runtime flow.

- [ ] **Step 3: Run all targeted tests**

```bash
TELESCOPE_ENABLED=false XDG_CONFIG_HOME=/tmp php artisan test \
  tests/Feature/ShopifyParentIdentityResolverTest.php \
  tests/Feature/ReplicateProductStrictIdentityTest.php \
  tests/Feature/ReplicateProductCreateParentIdentityTest.php \
  tests/Feature/BemWatermarkUpdateBootstrapTest.php \
  tests/Feature/BemWatermarkUpdateManifestTest.php \
  tests/Feature/BemWatermarkFlowTest.php
```

Expected: all tests PASS, with only explicit environment skips if PDO SQLite or an image extension is unavailable.

- [ ] **Step 4: Run the complete test suite**

```bash
TELESCOPE_ENABLED=false XDG_CONFIG_HOME=/tmp php artisan test
```

Expected: PASS. Record any pre-existing unrelated failure separately; do not hide it.

- [ ] **Step 5: Review the final diff for BEM scope**

```bash
git diff --check
git diff --stat
git diff -- app/Services/Shopify/BemWatermark/BemWatermarkUpdateBootstrapService.php
```

Expected: BEM diff changes constructor/product resolution and related tests only; no watermark rendering, image replacement, manifest, staged-upload, or retry implementation changes.

- [ ] **Step 6: Reload application caches and queue workers**

After tests pass:

```bash
php artisan optimize:clear
php artisan queue:restart
```

Expected: caches clear successfully and workers receive the restart signal. If the queue restart cache store fails, report the exact error and restart the configured process manager only after confirming its worker name.

- [ ] **Step 7: Validate controlled draft products**

Use one newly created draft product and one existing product whose target product and variants already have correct parent IDs. Confirm logs show:

```text
parentproduct
parentvariant
```

and do not show:

```text
matched_by: handle
matched_by: sku
matched_by: options_key
matched_by: single_variant
```

Do not run a full-store scan or backfill during this rollout.

- [ ] **Step 8: Commit any final test-only adjustments**

```bash
git add tests app/Services/Shopify/ShopifyParentIdentityResolver.php app/Jobs/ReplicateProductUpdateToShop.php app/Jobs/ReplicateProductCreateToShop.php app/Services/Shopify/BemWatermark/BemWatermarkUpdateBootstrapService.php
git commit -m "test: cover strict parent identity replication"
```
