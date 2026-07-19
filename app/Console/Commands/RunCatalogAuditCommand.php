<?php

namespace App\Console\Commands;

use App\Jobs\Shopify\RunCatalogAuditForShop;
use App\Models\CatalogAuditRun;
use App\Models\Shop;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunCatalogAuditCommand extends Command
{
    protected $signature = 'catalog-audit:scan {--shop= : Shop slug, domain, or numeric ID}';

    protected $description = 'Queue catalog audits for active configured Shopify shops.';

    public function handle(): int
    {
        $configuredShops = config('catalog_audit.shops', []);
        if (! is_array($configuredShops)) {
            $this->error('Catalog audit shop configuration is invalid.');

            return self::FAILURE;
        }

        $configuredShops = array_filter(
            $configuredShops,
            static fn (mixed $domain): bool => is_string($domain) && $domain !== ''
        );
        $activeShops = Shop::query()
            ->where('is_active', true)
            ->whereIn('domain', array_values($configuredShops))
            ->get();

        $shops = $this->resolveShops($configuredShops, $activeShops);
        if ($shops === null) {
            return self::FAILURE;
        }

        if ($shops->isEmpty()) {
            $this->info('No active configured catalog-audit shops found.');

            return self::SUCCESS;
        }

        if (! $this->usesDatabaseQueueOnApplicationConnection()) {
            return self::FAILURE;
        }

        $queue = (string) config('catalog_audit.queue', 'catalog_audit');
        try {
            $jobCount = DB::transaction(function () use ($shops, $queue): int {
                $jobCount = 0;
                foreach ($shops as $shop) {
                    $run = CatalogAuditRun::create([
                        'shop_id' => $shop->id,
                        'status' => CatalogAuditRun::STATUS_QUEUED,
                    ]);

                    $job = (new RunCatalogAuditForShop($run->id, $shop->id))->onQueue($queue);
                    Bus::dispatch($job);
                    $jobCount++;
                }

                return $jobCount;
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Catalog audit runs could not be queued.');

            return self::FAILURE;
        }

        $this->info('Queued '.$jobCount.' catalog audit run(s).');

        return self::SUCCESS;
    }

    private function usesDatabaseQueueOnApplicationConnection(): bool
    {
        $queueConnection = (string) config('catalog_audit.connection', 'database_catalog_audit');
        $queueConfiguration = config("queue.connections.{$queueConnection}", []);
        $queueDriver = is_array($queueConfiguration) ? ($queueConfiguration['driver'] ?? null) : null;
        $queueDatabaseConnection = is_array($queueConfiguration)
            ? ($queueConfiguration['connection'] ?? config('database.default'))
            : null;

        if ($queueDriver !== 'database' || $queueDatabaseConnection !== config('database.default')) {
            $this->error('Catalog audits require a database queue on the application database connection.');

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, string>  $configuredShops
     * @param  Collection<int, Shop>  $activeShops
     * @return Collection<int, Shop>|null
     */
    private function resolveShops(array $configuredShops, Collection $activeShops): ?Collection
    {
        $byDomain = $activeShops->keyBy(
            static fn (Shop $shop): string => strtolower((string) $shop->domain)
        );
        $byId = $activeShops->keyBy('id');
        $selector = trim((string) $this->option('shop'));

        if ($selector !== '') {
            $shop = null;
            if (array_key_exists($selector, $configuredShops)) {
                $shop = $byDomain->get(strtolower($configuredShops[$selector]));
            } elseif (ctype_digit($selector)) {
                $shop = $byId->get((int) $selector);
            } else {
                $shop = $byDomain->get(strtolower($selector));
            }

            if (! $shop instanceof Shop) {
                $this->error("Shop [{$selector}] is unknown, inactive, or not configured for catalog audits.");

                return null;
            }

            return collect([$shop]);
        }

        return collect($configuredShops)
            ->map(fn (string $domain): ?Shop => $byDomain->get(strtolower($domain)))
            ->filter()
            ->values();
    }
}
