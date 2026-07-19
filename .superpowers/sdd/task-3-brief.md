### Task 3: Bulk Operation Reader

**Files:**
- Create: `app/Services/Shopify/CatalogAuditBulkService.php`
- Test: `tests/Feature/CatalogAuditBulkServiceTest.php`

**Interfaces:**
- Consumes: active `Shop`, timeout, and poll interval.
- Produces: `downloadSnapshot(Shop $shop, int $timeoutSeconds, int $pollSeconds): string`.

- [ ] **Step 1: Write failing HTTP-fake tests**

Fake three boundaries: start mutation returns an operation ID, current operation reaches `COMPLETED`, and the signed result URL returns JSONL. Assert the mutation embeds a bulk query for active products, `images(first: 1)`, and variants with SKU. Add failure tests for GraphQL user errors, terminal failed status, timeout, and missing result URL.

- [ ] **Step 2: Run the bulk service tests and verify they fail**

Run:

```bash
php artisan test tests/Feature/CatalogAuditBulkServiceTest.php
```

- [ ] **Step 3: Implement the read-only bulk service**

Use the shop's configured API version and access token. The embedded bulk query must be equivalent to:

```graphql
{
  products(query: "status:active") {
    edges {
      node {
        id
        legacyResourceId
        title
        handle
        status
        images(first: 1) { edges { node { id } } }
        variants { edges { node { id legacyResourceId title sku } } }
      }
    }
  }
}
```

Only `bulkOperationRunQuery` is a mutation; it starts a read operation and does not modify catalog data. Poll `currentBulkOperation(type: QUERY)`, reject operation-ID changes, and download `url` with fallback to `partialDataUrl` only when Shopify reports completion.

- [ ] **Step 4: Run the bulk service tests**

Expected: all HTTP-fake tests pass with no live Shopify calls.

---

