<?php

namespace App\Services\Shopify;

use App\Models\CatalogAuditFinding;
use App\Models\CatalogAuditRun;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CatalogAuditReconciler
{
    private const MUTABLE_FINDING_COLUMNS = [
        'last_seen_run_id',
        'product_gid',
        'product_legacy_id',
        'product_title',
        'product_handle',
        'product_status',
        'variant_gid',
        'variant_legacy_id',
        'variant_title',
        'sku',
        'normalized_sku',
        'shopify_admin_url',
        'last_seen_at',
        'updated_at',
    ];

    /** @param array<string, mixed> $parsed */
    public function reconcile(CatalogAuditRun $run, array $parsed): void
    {
        [$findings, $counts] = $this->validatedPayload($parsed);
        $shopId = (int) $run->shop_id;
        $runId = (int) $run->getKey();

        $this->assertPersistedRunMatchesShop($run, $runId, $shopId);

        $timestamp = now()->toDateTimeString();
        $rows = [];
        foreach ($findings as $finding) {
            $rows[] = [
                ...$finding,
                'shop_id' => $shopId,
                'last_seen_run_id' => $runId,
                'last_seen_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        $runValues = [
            'status' => CatalogAuditRun::STATUS_COMPLETED,
            'finished_at' => $timestamp,
            'missing_image_count' => $counts['missing_image_count'],
            'duplicate_sku_group_count' => $counts['duplicate_sku_group_count'],
            'duplicate_sku_row_count' => $counts['duplicate_sku_row_count'],
            'updated_at' => $timestamp,
        ];

        DB::transaction(function () use ($rows, $runId, $shopId, $runValues): void {
            if ($rows === []) {
                CatalogAuditFinding::query()
                    ->where('shop_id', $shopId)
                    ->delete();
            } else {
                CatalogAuditFinding::upsert(
                    $rows,
                    ['shop_id', 'finding_type', 'fingerprint'],
                    self::MUTABLE_FINDING_COLUMNS
                );

                CatalogAuditFinding::query()
                    ->where('shop_id', $shopId)
                    ->where('last_seen_run_id', '!=', $runId)
                    ->delete();
            }

            $updated = CatalogAuditRun::query()
                ->whereKey($runId)
                ->where('shop_id', $shopId)
                ->update($runValues);

            if ($updated !== 1) {
                throw new InvalidArgumentException('Catalog audit run shop changed during reconciliation.');
            }
        });

        $run->forceFill($runValues);
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, int>}
     */
    private function validatedPayload(array $parsed): array
    {
        if (! array_key_exists('findings', $parsed) || ! is_array($parsed['findings'])) {
            throw new InvalidArgumentException("Parsed catalog audit payload must contain a 'findings' array.");
        }

        foreach ($parsed['findings'] as $finding) {
            if (! is_array($finding)) {
                throw new InvalidArgumentException('Each parsed catalog audit finding must be an array.');
            }
        }

        $counts = [];
        foreach ([
            'missing_image_count',
            'duplicate_sku_group_count',
            'duplicate_sku_row_count',
        ] as $key) {
            if (! array_key_exists($key, $parsed) || ! is_int($parsed[$key]) || $parsed[$key] < 0) {
                throw new InvalidArgumentException("Parsed catalog audit payload must contain a non-negative integer '{$key}'.");
            }

            $counts[$key] = $parsed[$key];
        }

        return [$parsed['findings'], $counts];
    }

    private function assertPersistedRunMatchesShop(CatalogAuditRun $run, int $runId, int $shopId): void
    {
        if (! $run->exists || $runId < 1 || $shopId < 1) {
            throw new InvalidArgumentException('Catalog audit run must be persisted with a shop.');
        }

        $persistedShopId = CatalogAuditRun::query()
            ->whereKey($runId)
            ->value('shop_id');

        if ($persistedShopId === null || (int) $persistedShopId !== $shopId) {
            throw new InvalidArgumentException('Catalog audit run shop does not match the persisted shop.');
        }
    }
}
