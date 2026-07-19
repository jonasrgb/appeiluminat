<?php

namespace Tests\Feature;

use App\Console\Commands\RunCatalogAuditCommand;
use App\Jobs\Shopify\RunCatalogAuditForShop;
use App\Models\CatalogAuditFinding;
use App\Models\CatalogAuditRun;
use App\Models\Shop;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class RunCatalogAuditForShopTest extends TestCase
{
    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required for isolated catalog audit orchestration tests.');
        }

        parent::setUp();

        $this->configureInMemoryDatabase();
        Config::set('catalog_audit.shops', [
            'first' => 'first.myshopify.com',
            'second' => 'second.myshopify.com',
        ]);
        Config::set('catalog_audit.queue', 'catalog_audit');
        Config::set('catalog_audit.connection', 'database_catalog_audit');
        Config::set('catalog_audit.timeout_seconds', 1);
        Config::set('catalog_audit.poll_seconds', 0);
        Config::set('queue.default', 'database');
    }

    public function test_command_creates_queued_runs_and_independent_database_jobs_in_config_order(): void
    {
        $first = $this->shop('first.myshopify.com');
        $second = $this->shop('second.myshopify.com');

        $this->artisan(RunCatalogAuditCommand::class)
            ->assertExitCode(0);

        $runs = CatalogAuditRun::query()->with('shop')->orderBy('id')->get();
        $queuedJobs = DB::table('jobs')->orderBy('id')->get();

        $this->assertSame(['first.myshopify.com', 'second.myshopify.com'], $runs->pluck('shop.domain')->all());
        $this->assertSame([CatalogAuditRun::STATUS_QUEUED, CatalogAuditRun::STATUS_QUEUED], $runs->pluck('status')->all());
        $this->assertCount(2, $queuedJobs);
        $this->assertSame(['catalog_audit', 'catalog_audit'], $queuedJobs->pluck('queue')->all());

        foreach ($queuedJobs as $index => $queuedJob) {
            $payload = json_decode($queuedJob->payload, true, 512, JSON_THROW_ON_ERROR);
            $job = unserialize($payload['data']['command']);

            $this->assertInstanceOf(RunCatalogAuditForShop::class, $job);
            $this->assertSame($runs[$index]->id, $job->runId);
            $this->assertSame([$first->id, $second->id][$index], $job->shopId);
            $this->assertSame('catalog_audit', $job->queue);
            $this->assertSame('database_catalog_audit', $job->connection);
            $this->assertSame([], $job->chained);
            $this->assertSame(0, $payload['maxTries']);
        }
    }

    public function test_command_resolves_one_active_configured_shop_by_slug_domain_or_id(): void
    {
        $first = $this->shop('first.myshopify.com');
        $second = $this->shop('second.myshopify.com');

        foreach (['first', 'second.myshopify.com', (string) $first->id] as $selector) {
            CatalogAuditRun::query()->delete();
            Bus::fake();

            $this->artisan(RunCatalogAuditCommand::class, ['--shop' => $selector])
                ->assertExitCode(0);

            $run = CatalogAuditRun::sole();
            $expected = $selector === 'second.myshopify.com' ? $second : $first;
            $this->assertSame($expected->id, $run->shop_id);
            Bus::assertDispatched(RunCatalogAuditForShop::class, function (RunCatalogAuditForShop $job) use ($run, $expected): bool {
                return $job->runId === $run->id && $job->shopId === $expected->id;
            });
        }
    }

    public function test_command_rejects_unknown_or_excluded_shops_before_creating_runs_or_dispatching(): void
    {
        $this->shop('first.myshopify.com', false);
        $this->shop('excluded.myshopify.com');

        foreach (['unknown', 'excluded.myshopify.com'] as $selector) {
            Bus::fake();

            $this->artisan(RunCatalogAuditCommand::class, ['--shop' => $selector])
                ->assertExitCode(1);

            $this->assertSame(0, CatalogAuditRun::query()->count());
            Bus::assertNothingDispatched();
        }
    }

    public function test_command_returns_success_without_dispatching_when_no_configured_shop_is_active(): void
    {
        $this->shop('first.myshopify.com', false);
        Bus::fake();

        $this->artisan(RunCatalogAuditCommand::class)
            ->assertExitCode(0);

        $this->assertSame(0, CatalogAuditRun::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_command_rejects_a_non_database_queue_before_creating_runs(): void
    {
        $this->shop('first.myshopify.com');
        Config::set('queue.connections.database_catalog_audit.driver', 'sync');

        $this->artisan(RunCatalogAuditCommand::class)
            ->assertExitCode(1);

        $this->assertSame(0, CatalogAuditRun::query()->count());
    }

    public function test_command_rolls_back_queued_runs_and_database_jobs_when_an_independent_dispatch_fails(): void
    {
        $this->shop('first.myshopify.com');
        $this->shop('second.myshopify.com');
        $dispatches = 0;
        Event::listen(JobQueueing::class, static function () use (&$dispatches): void {
            $dispatches++;

            if ($dispatches === 2) {
                throw new RuntimeException('Queue dispatch failed.');
            }
        });

        $this->artisan(RunCatalogAuditCommand::class)
            ->assertExitCode(1);

        $this->assertSame(0, CatalogAuditRun::query()->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_job_reconciles_a_snapshot_after_marking_its_queued_run_running(): void
    {
        $shop = $this->shop('first.myshopify.com');
        $run = $this->queuedRun($shop);
        Http::fakeSequence()
            ->push(['data' => ['bulkOperationRunQuery' => [
                'bulkOperation' => ['id' => 'gid://shopify/BulkOperation/1', 'status' => 'CREATED'],
                'userErrors' => [],
            ]]], 200)
            ->push(['data' => ['currentBulkOperation' => [
                'id' => 'gid://shopify/BulkOperation/1',
                'status' => 'COMPLETED',
                'url' => 'https://snapshot.test/catalog.jsonl',
            ]]], 200)
            ->push("{\"id\":\"gid://shopify/Product/1\",\"legacyResourceId\":\"1\",\"title\":\"Lamp\",\"handle\":\"lamp\",\"status\":\"ACTIVE\"}\n", 200);

        (new RunCatalogAuditForShop($run->id, $shop->id))->handle(
            app(\App\Services\Shopify\CatalogAuditBulkService::class),
            app(\App\Services\Shopify\CatalogAuditJsonlParser::class),
            app(\App\Services\Shopify\CatalogAuditReconciler::class),
        );

        $run->refresh();
        $this->assertSame(CatalogAuditRun::STATUS_COMPLETED, $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertSame(1, CatalogAuditFinding::where('shop_id', $shop->id)->count());
    }

    public function test_job_marks_a_caught_scan_error_failed_without_releasing_or_touching_other_queued_runs(): void
    {
        $shop = $this->shop('first.myshopify.com');
        $run = $this->queuedRun($shop);
        $this->finding($shop, $this->completedRun($shop));
        $otherRun = $this->queuedRun($this->shop('second.myshopify.com'));
        Http::fake(['*' => Http::response([], 500)]);

        (new RunCatalogAuditForShop($run->id, $shop->id))->handle(
            app(\App\Services\Shopify\CatalogAuditBulkService::class),
            app(\App\Services\Shopify\CatalogAuditJsonlParser::class),
            app(\App\Services\Shopify\CatalogAuditReconciler::class),
        );

        $run->refresh();
        $otherRun->refresh();
        $this->assertSame(CatalogAuditRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, CatalogAuditFinding::where('shop_id', $shop->id)->count());
        $this->assertSame(CatalogAuditRun::STATUS_QUEUED, $otherRun->status);
    }

    public function test_failed_callback_marks_only_its_matching_queued_run_failed(): void
    {
        $shop = $this->shop('first.myshopify.com');
        $run = $this->queuedRun($shop);
        $this->finding($shop, $this->completedRun($shop));
        $otherRun = $this->queuedRun($this->shop('second.myshopify.com'));

        (new RunCatalogAuditForShop($run->id, $shop->id))->failed(new RuntimeException('Worker timeout.'));

        $run->refresh();
        $otherRun->refresh();
        $this->assertSame(CatalogAuditRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNotSame('', $run->error_message);
        $this->assertSame(1, CatalogAuditFinding::where('shop_id', $shop->id)->count());
        $this->assertSame(CatalogAuditRun::STATUS_QUEUED, $otherRun->status);
    }

    public function test_failed_callback_treats_a_completed_run_idempotently(): void
    {
        $shop = $this->shop('first.myshopify.com');
        $run = $this->completedRun($shop);

        (new RunCatalogAuditForShop($run->id, $shop->id))->failed(new RuntimeException('Worker timeout.'));

        $run->refresh();
        $this->assertSame(CatalogAuditRun::STATUS_COMPLETED, $run->status);
        $this->assertNull($run->error_message);
    }

    private function shop(string $domain, bool $active = true): Shop
    {
        return Shop::create([
            'name' => $domain,
            'domain' => $domain,
            'access_token' => 'catalog-audit-token',
            'api_version' => '2025-01',
            'is_active' => $active,
        ]);
    }

    private function queuedRun(Shop $shop): CatalogAuditRun
    {
        return CatalogAuditRun::create([
            'shop_id' => $shop->id,
            'status' => CatalogAuditRun::STATUS_QUEUED,
        ]);
    }

    private function completedRun(Shop $shop): CatalogAuditRun
    {
        return CatalogAuditRun::create([
            'shop_id' => $shop->id,
            'status' => CatalogAuditRun::STATUS_COMPLETED,
            'finished_at' => now(),
        ]);
    }

    private function finding(Shop $shop, CatalogAuditRun $run): void
    {
        CatalogAuditFinding::create([
            'shop_id' => $shop->id,
            'last_seen_run_id' => $run->id,
            'finding_type' => CatalogAuditFinding::TYPE_MISSING_IMAGE,
            'fingerprint' => 'missing_image:gid://shopify/Product/999',
            'product_gid' => 'gid://shopify/Product/999',
            'last_seen_at' => now(),
        ]);
    }

    private function configureInMemoryDatabase(): void
    {
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        Config::set('database.connections.sqlite.foreign_key_constraints', true);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('domain')->unique();
            $table->text('access_token');
            $table->string('api_version')->default('2025-01');
            $table->boolean('is_source')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('location_legacy_id')->nullable();
            $table->timestamps();
        });

        Schema::create('catalog_audit_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('status', 20);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('missing_image_count')->default(0);
            $table->unsignedInteger('duplicate_sku_group_count')->default(0);
            $table->unsignedInteger('duplicate_sku_row_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('catalog_audit_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('last_seen_run_id')->constrained('catalog_audit_runs')->restrictOnDelete();
            $table->string('finding_type', 30);
            $table->string('fingerprint');
            $table->string('product_gid');
            $table->unsignedBigInteger('product_legacy_id')->nullable();
            $table->string('product_title')->nullable();
            $table->string('product_handle')->nullable();
            $table->string('product_status')->nullable();
            $table->string('variant_gid')->nullable();
            $table->unsignedBigInteger('variant_legacy_id')->nullable();
            $table->string('variant_title')->nullable();
            $table->string('sku')->nullable();
            $table->string('normalized_sku')->nullable();
            $table->string('shopify_admin_url')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['shop_id', 'finding_type', 'fingerprint']);
        });
    }
}
