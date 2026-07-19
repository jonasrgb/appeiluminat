# BEM Target Media Reconciliation Design

## Problem

`BemSyncBackupManifestFromSourceUpdate` treats a source product whose current
images match `prod.watermarked` as a global no-op. This is incorrect after a
partial fan-out failure. A staged-upload timeout can leave one target without
images while the source, backup, and earlier targets are already complete. On
retry, the source-level no-op returns before the incomplete target is checked.

## Scope

This change applies only to BEM image synchronization for configured watermark
targets. It does not alter product identity resolution, watermark generation,
product core synchronization, variants, status, publishing, or the backup
manifest format.

## Design

Extract target inspection and repair into a focused reconciliation service.
The source update job will invoke it for every eligible target mirror both:

1. after a normal source/backup media update; and
2. when the source itself is a no-op.

The early source no-op return is therefore replaced by target reconciliation.

### Target Health Contract

A target is healthy only when all of the following are true:

- the target product still exists;
- the number of live target images equals the number of clean backup images;
- `prod.watermarked.images` has the same number of entries;
- each manifest entry has status `completed`;
- at every position, the live target image URL matches the manifest
  `watermarked_url` after URL canonicalization; and
- at every position, the manifest `source_url` matches the current clean
  backup image URL after URL canonicalization.

Zero desired images is invalid and must never be treated as healthy.

### Repair

For an unhealthy target, the existing BEM processor applies that target's
watermark to the current clean backup images. The existing staged-upload
service replaces target media only after the new media is ready. The target
`prod.watermarked` payload and local mirror snapshot are updated only after a
successful replacement.

Healthy targets receive no writes.

### Failure Isolation And Retry

Targets are reconciled one at a time. A failure is recorded for that target,
but later targets are still inspected and repaired. After all targets have
been attempted, the job throws an aggregate runtime error when any target
failed, preserving the existing queue retry behavior.

On retry, the source may be a no-op, but target reconciliation still runs.
Targets already healthy are skipped; only incomplete targets are regenerated.
This makes retries idempotent at the target level.

If a target product is missing, its mirror is not silently rewritten by this
service. Existing strict `custom.parentproduct` bootstrap remains responsible
for identity repair.

## Logging

Add structured logs for:

- healthy target skipped;
- unhealthy target with explicit reasons;
- target repair completed;
- target repair failed while processing continues; and
- aggregate reconciliation failure that triggers retry.

Logs include source product ID, target shop, target product GID, expected image
count, actual image count, and manifest image count.

## Tests

Regression coverage must prove:

- source no-op plus target with zero images repairs that target;
- a healthy target performs no media or metafield writes;
- missing or mismatched target manifest triggers repair;
- one target failure does not prevent a later target from being attempted;
- the job throws after all targets when any repair failed; and
- retry skips targets that became healthy and repairs only remaining targets.

The production fix follows test-first development: each new behavior is first
captured by a failing test, then implemented minimally.
