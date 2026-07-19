# Nightly Shopify Catalog Audit

## Objective

Run a read-only Shopify catalog audit every night at 01:00 Europe/Bucharest for:

- `eiluminat.myshopify.com`
- `lustreled.myshopify.com`
- `powerleds-ro.myshopify.com`
- `iluminat-industrial.myshopify.com`
- `eiluminat-bg.myshopify.com`

The backup shop is intentionally excluded.

The audit maintains two current issue lists per shop:

1. Active products without images.
2. Active products whose non-empty variant SKUs are duplicated within the same shop.

The dashboard must always represent the latest successful complete scan. Resolved findings disappear after the next successful scan.

## Audit Rules

Only products with Shopify status `ACTIVE` participate in either audit.

A product is missing images when Shopify reports no product images. Other product media must not be treated as an image.

SKU comparison ignores empty values. Values are normalized by trimming surrounding whitespace and applying case-insensitive comparison. For example, `ABC123`, `abc123`, and ` ABC123 ` belong to the same duplicate group.

Duplicate SKUs are scoped to one shop. The same SKU appearing in two different shops is not a duplicate group.

The audit never writes to Shopify.

## Shopify Data Acquisition

Use one Shopify Bulk Operation per shop. The query loads active products with the fields required by both audits:

- product GID and legacy ID;
- title, handle, and status;
- at least one image connection result;
- variants with GID, legacy ID, title, and SKU.

The JSONL parser reconstructs product-to-variant and product-to-image relationships through Shopify `__parentId` values. A single downloaded snapshot is used to calculate both finding types.

The command independently queues one `catalog_audit` job per configured shop in configuration order on the dedicated `database_catalog_audit` connection. A shared global queue mutex serializes all catalog-audit jobs, so manual and nightly runs execute one at a time without making later shops depend on a predecessor's success. The dedicated connection and queue name keep the 2700-second reservation window and workload isolated from the existing application queues, including the pre-existing `bulk_ops` queue.

## Persistence Model

### `catalog_audit_runs`

Stores execution state per shop:

- shop ID;
- status: queued, running, completed, or failed;
- start and finish timestamps;
- missing-image product count;
- duplicate-SKU group count;
- duplicate-SKU affected-row count;
- error message when failed.

### `catalog_audit_findings`

Stores current findings:

- shop ID and last successful run ID;
- finding type: `missing_image` or `duplicate_sku`;
- stable fingerprint unique within shop and finding type;
- product GID and legacy ID;
- product title, handle, and status;
- variant GID, legacy ID, and title when applicable;
- original SKU and normalized SKU when applicable;
- Shopify Admin product URL;
- last-seen timestamp.

A missing-image fingerprint identifies a product. A duplicate-SKU fingerprint identifies an affected variant within a normalized SKU group.

## Atomic Reconciliation

Parsing and validation complete before current findings are changed.

For each successful shop scan, one database transaction:

1. Upserts every finding from the new snapshot.
2. Marks it with the successful run ID.
3. Deletes findings for that shop that were not observed in the new run.
4. Marks the run completed with final counts.

An empty result is valid and removes all existing findings for that shop.

If Shopify, download, parsing, or persistence fails, the run is marked failed and the previous successful findings remain unchanged. A failed shop does not prevent later shops in the sequence from running.

## Scheduling and Commands

A dedicated command creates runs and independently dispatches the five shop jobs in configuration order within one transaction on the validated `database_catalog_audit` connection. A dispatch failure rolls back both run rows and database-queue rows. It supports selecting one shop for manual validation.

The Laravel scheduler runs it daily at `01:00` in `Europe/Bucharest`, guarded by `withoutOverlapping()`. The existing midnight MiniCRM missing-image command remains unchanged.

Each shop job has unlimited queue attempts because a global-lock release consumes an attempt. It uses a shared `catalog-audit-global` `WithoutOverlapping` mutex with a 60-second release delay and 2400-second expiry. Caught scan failures mark only that run failed and return; timeout and fatal callbacks best-effort mark only queued or running runs failed. No job dispatches or depends on a chain successor.

## Dashboard

All routes require the existing authenticated dashboard session. Email verification is intentionally not introduced here because the current `User` model does not implement `MustVerifyEmail`, and enabling it globally would change access for existing accounts outside this feature.

Pages:

- `/dashboard/catalog-audit/{shop}/missing-images`
- `/dashboard/catalog-audit/{shop}/duplicate-skus`

The UI uses custom CSS consistent with the existing duplicate-product page and does not rely on dark-mode Tailwind styling for the audit tables.

Both pages provide:

- tabs for the five shops;
- tabs for the two audit types;
- latest scan status and timestamp;
- current finding totals;
- search by product ID, title, handle, or SKU as applicable;
- pagination;
- direct links to Shopify Admin.

Missing images are shown one product per row. Duplicate SKUs are paginated and displayed by normalized SKU group, with every affected active product and variant visible inside the group.

When the latest run failed, the dashboard keeps the previous findings visible and displays that they may be stale along with the failure timestamp and a concise error.

## Testing

Automated tests cover:

- JSONL reconstruction for product, image, and variant records;
- active-only filtering;
- image detection independent of non-image media;
- empty SKU exclusion and case-insensitive trimmed normalization;
- duplicate grouping;
- atomic removal of resolved findings after success;
- preservation of previous findings after failure;
- independent queued jobs surviving another shop's failure;
- authentication on dashboard routes;
- filtering, grouping, and pagination queries;
- scheduler time and timezone configuration.

No full test suite is run against the live MySQL database. Focused tests use isolated test data and HTTP fakes.

## Out of Scope

- Modifying products, variants, images, or SKUs in Shopify.
- Sending audit email or MiniCRM notifications.
- Auditing draft, archived, or backup-shop products.
- Historical trend charts beyond run status and counts.
- Cross-shop SKU duplicate detection.
