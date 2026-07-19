<?php

namespace Tests\Unit;

use App\Console\Commands\RunCatalogAuditCommand;
use App\Jobs\Shopify\RunCatalogAuditForShop;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use ReflectionMethod;
use Tests\TestCase;

class CatalogAuditCommandContractTest extends TestCase
{
    public function test_command_exposes_the_catalog_audit_scan_contract_without_accessing_a_database(): void
    {
        $path = app_path('Console/Commands/RunCatalogAuditCommand.php');

        $this->assertFileExists($path);

        $command = new RunCatalogAuditCommand;

        $this->assertSame('catalog-audit:scan', $command->getName());
        $this->assertTrue($command->getDefinition()->hasOption('shop'));

        $source = (string) file_get_contents($path);
        $this->assertStringContainsString('CatalogAuditRun::create', $source);
        $this->assertStringContainsString('CatalogAuditRun::STATUS_QUEUED', $source);
        $this->assertStringContainsString('Bus::dispatch($job)', $source);
        $this->assertStringNotContainsString('Bus::chain', $source);
        $this->assertStringContainsString("config('catalog_audit.queue'", $source);
        $this->assertStringContainsString("config('catalog_audit.connection'", $source);
        $this->assertStringContainsString('$queueDriver !== \'database\'', $source);
        $this->assertStringContainsString('DB::transaction', $source);
    }

    public function test_job_has_the_required_queue_and_overlap_contract_without_accessing_a_database(): void
    {
        $path = app_path('Jobs/Shopify/RunCatalogAuditForShop.php');

        $this->assertFileExists($path);

        $job = new RunCatalogAuditForShop(41, 7);
        $constructor = new ReflectionMethod($job, '__construct');
        $parameters = $constructor->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertSame('runId', $parameters[0]->getName());
        $this->assertTrue($parameters[0]->isPromoted());
        $this->assertSame('int', (string) $parameters[0]->getType());
        $this->assertSame('shopId', $parameters[1]->getName());
        $this->assertTrue($parameters[1]->isPromoted());
        $this->assertSame('int', (string) $parameters[1]->getType());
        $this->assertSame(0, $job->tries);
        $this->assertSame(1800, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame('catalog_audit', $job->queue);
        $this->assertSame('database_catalog_audit', $job->connection);

        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('catalog-audit-global', $middleware[0]->key);
        $this->assertSame(60, $middleware[0]->releaseAfter);
        $this->assertSame(2400, $middleware[0]->expiresAfter);
        $this->assertTrue($middleware[0]->shareKey);

        $retryAfter = (int) config('queue.connections.database_catalog_audit.retry_after');

        $this->assertGreaterThan($job->timeout, $retryAfter);
        $this->assertGreaterThan($middleware[0]->expiresAfter, $retryAfter);
    }

    public function test_job_marks_only_its_queued_or_running_run_failed_without_chain_or_attempt_retries(): void
    {
        $source = (string) file_get_contents(app_path('Jobs/Shopify/RunCatalogAuditForShop.php'));

        $this->assertStringContainsString('public function failed(Throwable $exception): void', $source);
        $this->assertStringContainsString('$this->markRunFailed($exception);', $source);
        $this->assertStringContainsString("->whereIn('status', [CatalogAuditRun::STATUS_QUEUED, CatalogAuditRun::STATUS_RUNNING])", $source);
        $this->assertStringNotContainsString('$this->release(', $source);
        $this->assertStringNotContainsString('$this->attempts()', $source);
        $this->assertStringNotContainsString('dispatchNextJobInChain', $source);
        $this->assertStringNotContainsString('Bus::chain', $source);
    }
}
