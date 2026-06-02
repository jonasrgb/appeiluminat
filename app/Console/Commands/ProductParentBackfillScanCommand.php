<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\Shopify\ProductParentBackfillService;
use Illuminate\Console\Command;

class ProductParentBackfillScanCommand extends Command
{
    protected $signature = 'product-parent:scan
        {--source-shop= : Source shop domain or ID}
        {--target-shop= : Target shop domain or ID}
        {--limit= : Limit products per shop for testing}
        {--apply : Write custom.parentproduct for clear matches}';

    protected $description = 'Scan Shopify products and prepare/apply custom.parentproduct backfill for target shops.';

    public function handle(ProductParentBackfillService $service): int
    {
        $sourceShop = $this->resolveSourceShop($this->option('source-shop'));
        $targetShop = $this->option('target-shop')
            ? $this->resolveShop($this->option('target-shop'))
            : null;
        $limit = $this->option('limit') !== null ? max(1, (int)$this->option('limit')) : null;
        $apply = (bool)$this->option('apply');

        $this->info('Product parent backfill started.');
        $this->line('Source: '.$sourceShop->domain);
        $this->line('Mode: '.($apply ? 'apply metafields' : 'scan only'));
        if ($targetShop) {
            $this->line('Target: '.$targetShop->domain);
        }
        if ($limit) {
            $this->line('Limit: '.$limit.' products per shop');
        }

        $progressBar = null;
        $progress = function (string $event, array $payload) use (&$progressBar): void {
            if ($event === 'source_scanned') {
                $this->line('Source products loaded: '.$payload['count']);
                return;
            }

            if ($event === 'target_started') {
                $this->newLine();
                $this->line('Scanning target '.$payload['shop'].'...');
                $progressBar = $this->output->createProgressBar((int)$payload['count']);
                $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
                $progressBar->start();
                return;
            }

            if ($event === 'target_product_processed' && $progressBar) {
                $progressBar->advance();
                return;
            }

            if ($event === 'target_finished' && $progressBar) {
                $progressBar->finish();
                $this->newLine(2);
                $progressBar = null;
            }
        };

        $summary = $service->scan($sourceShop, $targetShop, $limit, $apply, $progress);

        $this->newLine();
        $this->info('Source products scanned: '.$summary['source_products']);

        foreach ($summary['targets'] as $targetSummary) {
            $this->newLine();
            $this->line('Target: '.$targetSummary['shop']);
            $this->line('Products: '.$targetSummary['products']);
            $this->line('Already set: '.$targetSummary['already_set']);
            $this->line('Matched: '.$targetSummary['matched']);
            $this->line('Unmatched: '.$targetSummary['unmatched']);
            $this->line('Ambiguous: '.$targetSummary['ambiguous']);
            if ($apply) {
                $this->line('Applied: '.$targetSummary['applied']);
                $this->line('Apply errors: '.$targetSummary['apply_errors']);
            }
        }

        return self::SUCCESS;
    }

    private function resolveSourceShop(?string $value): Shop
    {
        if ($value) {
            return $this->resolveShop($value);
        }

        return Shop::query()
            ->where('is_source', true)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function resolveShop(string $value): Shop
    {
        $query = Shop::query()->where('is_active', true);

        if (ctype_digit($value)) {
            return (clone $query)->where('id', (int)$value)->firstOrFail();
        }

        return $query->where('domain', $value)->firstOrFail();
    }
}
