# Safe BEM Media Orchestration Design

## Scope

Prevent source, backup, Lustreled, and Powerled products from losing all images
while BEM uploads replacement media. Hydrate an empty product-create webhook from
the live source product before deciding that it has no media.

Industrial and Bulgaria are outside this rollout.

## Safe Replacement Contract

1. Snapshot the current image media IDs.
2. Upload and append all replacement images without deleting current images.
3. Track the exact media IDs returned by `productCreateMedia`.
4. Poll those IDs until every replacement is `READY` and has a Shopify CDN URL.
5. Abort without deleting current media if any replacement fails or times out.
6. Delete only the image IDs from the initial snapshot.
7. Persist final CDN URLs and the existing BEM metafields/manifests.

A retry may find replacement media left by a previous failed attempt. It appends
a fresh complete set and deletes the initial snapshot only after the fresh set is
ready, so the product is never intentionally left without images.

## Create Webhook Media Race

When an eligible create webhook contains no images, query the source product's
live media through GraphQL. If image media is processing, release the queue job
before target creation. If ready images exist, hydrate the payload and continue
through the unchanged BEM processor. If Shopify still reports no media, allow a
short grace period before treating the product as genuinely image-less.

The target keeps the source status. Tests and live verification use only DRAFT,
unpublished products.

## Error Handling

- A missing created media ID is a hard error.
- `FAILED`, missing URL, or readiness timeout is a hard error before deletion.
- Non-image media is never included in the deletion snapshot.
- Existing watermark generation, naming, manifests, and parent identity rules do
  not change.

## Verification

- Feature tests prove create happens before delete and deletion is skipped when
  appended media is not ready.
- Unit/feature tests prove a live READY source image hydrates an empty webhook and
  PROCESSING media delays target creation.
- Existing BEM and strict identity suites remain green.
- A DRAFT, unpublished E2E product is created and updated on Lustreled, Powerled,
  and backup; every final image must be READY and every product unpublished.
