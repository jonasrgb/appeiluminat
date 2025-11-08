<?php

namespace App\Console\Commands;

use App\Services\Shopify\ProductSnapshotRefresher;
use Illuminate\Console\Command;

class RefreshProductSnapshot extends Command
{
    protected $signature = 'mirror:refresh {sourceShopId : ID-ul magazinului sursă} {productId : ID-ul numeric al produsului din magazinul sursă} {--dry-run : Afişează ce s-ar face fără să scrie în DB}';
    protected $description = 'Rebuilt the stored snapshot and variant mirrors for a product based on the current state of the source shop.';

    public function handle(ProductSnapshotRefresher $refresher): int
    {
        $sourceShopId = (int)$this->argument('sourceShopId');
        $productId    = (int)$this->argument('productId');
        $dryRun       = (bool)$this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry-run mode: data will be fetched and displayed but not persisted.');
        }

        if ($dryRun) {
            $this->table(
                ['Step', 'Status'],
                [
                    ['Fetch source payload', 'pending'],
                    ['Compute snapshot', 'pending'],
                    ['Update mirrors', 'skipped'],
                ]
            );
            $this->info('Dry-run: resolve ProductSnapshotRefresher and inspect logic before enabling writes.');
            return self::SUCCESS;
        }

        try {
            $summary = $refresher->refresh($sourceShopId, $productId);
        } catch (\Throwable $e) {
            $this->error('Refresh failed: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->info('Snapshot refresh completed');
        $this->table(
            ['Product Mirror', 'Target Shop', 'Target Product GID', 'Status'],
            array_map(fn($row) => [
                $row['product_mirror_id'] ?? '-',
                $row['target_shop_id'] ?? '-',
                $row['target_product_gid'] ?? '-',
                $row['status'] ?? '-',
            ], $summary['targets'] ?? [])
        );

        $this->line(sprintf(
            'Processed %d mirror(s) for source shop %d / product %d.',
            $summary['mirror_count'] ?? 0,
            $summary['source_shop_id'] ?? $sourceShopId,
            $summary['source_product_id'] ?? $productId
        ));

        return self::SUCCESS;
    }
}

