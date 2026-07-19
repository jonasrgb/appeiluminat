### Task 2: Pure Shopify JSONL Parser

**Files:**
- Create: `app/Services/Shopify/CatalogAuditJsonlParser.php`
- Test: `tests/Unit/CatalogAuditJsonlParserTest.php`

**Interfaces:**
- Produces: `parse(string $jsonl, Shop $shop): array`.
- Return shape:

```php
[
    'findings' => array<int, array<string, mixed>>,
    'missing_image_count' => int,
    'duplicate_sku_group_count' => int,
    'duplicate_sku_row_count' => int,
]
```

- [ ] **Step 1: Write failing parser tests**

Cover order-independent JSONL containing:

```json
{"id":"gid://shopify/Product/100","legacyResourceId":"100","title":"Lamp A","handle":"lamp-a","status":"ACTIVE"}
{"id":"gid://shopify/ProductVariant/1001","legacyResourceId":"1001","title":"Red","sku":" SKU-1 ","__parentId":"gid://shopify/Product/100"}
{"id":"gid://shopify/Product/200","legacyResourceId":"200","title":"Lamp B","handle":"lamp-b","status":"ACTIVE"}
{"id":"gid://shopify/ProductImage/900","__parentId":"gid://shopify/Product/200"}
{"id":"gid://shopify/ProductVariant/2001","legacyResourceId":"2001","title":"Default","sku":"sku-1","__parentId":"gid://shopify/Product/200"}
```

Assert product 100 is missing an image, product 200 is not, and the two variants form one duplicate group. Add cases for DRAFT products, blank SKUs, duplicate variants in one product, image rows arriving before product rows, and a video/media row that is not a product image.

- [ ] **Step 2: Run parser tests and verify they fail**

Run:

```bash
php artisan test tests/Unit/CatalogAuditJsonlParserTest.php
```

- [ ] **Step 3: Implement the parser**

Classify records by GID resource type, retain only active product IDs, and normalize SKU with:

```php
private function normalizeSku(?string $sku): ?string
{
    $trimmed = trim((string) $sku);

    return $trimmed === '' ? null : mb_strtolower($trimmed);
}
```

Build stable finding fingerprints from product GID for missing images and normalized SKU plus variant GID for duplicate rows. Generate Admin links using the first segment of the `.myshopify.com` domain.

- [ ] **Step 4: Run parser tests**

Expected: all parser tests pass without a database or network connection.

---

