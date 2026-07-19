<?php

namespace App\Jobs\Shopify;

use App\Models\CatalogAuditRun;
use App\Models\Shop;
use App\Services\Shopify\CatalogAuditBulkService;
use App\Services\Shopify\CatalogAuditJsonlParser;
use App\Services\Shopify\CatalogAuditReconciler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunCatalogAuditForShop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId, public int $shopId)
    {
        $this->onConnection((string) config('catalog_audit.connection', 'database_catalog_audit'));
        $this->onQueue((string) config('catalog_audit.queue', 'catalog_audit'));
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('catalog-audit-global'))
                ->releaseAfter(60)
                ->expireAfter(2400)
                ->shared(),
        ];
    }

    public function handle(
        CatalogAuditBulkService $bulkService,
        CatalogAuditJsonlParser $parser,
        CatalogAuditReconciler $reconciler,
    ): void {
        try {
            [$run, $shop] = $this->validatedRunAndShop();

            $run->forceFill([
                'status' => CatalogAuditRun::STATUS_RUNNING,
                'started_at' => $run->started_at ?? now(),
                'error_message' => null,
            ])->save();

            $snapshot = $bulkService->downloadSnapshot(
                $shop,
                (int) config('catalog_audit.timeout_seconds', 1200),
                (int) config('catalog_audit.poll_seconds', 5),
            );

            $reconciler->reconcile($run, $parser->parse($snapshot, $shop));
        } catch (Throwable $exception) {
            $this->markRunFailed($exception);
        }
    }

    public function failed(Throwable $exception): void
    {
        try {
            $this->markRunFailed($exception);
        } catch (Throwable $markFailure) {
            Log::error('Catalog audit job could not persist its failed callback state.', [
                ...$this->logContext($exception),
                'mark_failure' => $markFailure,
            ]);
        }
    }

    /** @return array{CatalogAuditRun, Shop} */
    private function validatedRunAndShop(): array
    {
        $run = CatalogAuditRun::query()
            ->whereKey($this->runId)
            ->where('shop_id', $this->shopId)
            ->first();
        $shop = Shop::query()
            ->whereKey($this->shopId)
            ->where('is_active', true)
            ->first();

        if ($run === null || $shop === null) {
            throw new \RuntimeException('Catalog audit run or active shop was not found.');
        }

        if (! in_array($run->status, [CatalogAuditRun::STATUS_QUEUED, CatalogAuditRun::STATUS_RUNNING], true)) {
            throw new \RuntimeException('Catalog audit run is not available to execute.');
        }

        $configuredDomains = array_filter(
            config('catalog_audit.shops', []),
            static fn (mixed $domain): bool => is_string($domain)
        );
        if (! in_array($shop->domain, $configuredDomains, true)) {
            throw new \RuntimeException('Catalog audit shop is not configured.');
        }

        return [$run, $shop];
    }

    private function markRunFailed(Throwable $exception): void
    {
        $message = mb_substr($exception->getMessage() ?: $exception::class, 0, 1000);

        $updated = CatalogAuditRun::query()
            ->whereKey($this->runId)
            ->where('shop_id', $this->shopId)
            ->whereIn('status', [CatalogAuditRun::STATUS_QUEUED, CatalogAuditRun::STATUS_RUNNING])
            ->update([
                'status' => CatalogAuditRun::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => $message,
            ]);

        if ($updated === 0) {
            return;
        }

        Log::error('Catalog audit job failed.', $this->logContext($exception));
    }

    /** @return array<string, mixed> */
    private function logContext(Throwable $exception): array
    {
        return [
            'run_id' => $this->runId,
            'shop_id' => $this->shopId,
            'exception' => $exception,
        ];
    }
}
