<?php

namespace Tests\Unit;

use Tests\TestCase;

class CatalogAuditScheduleContractTest extends TestCase
{
    public function test_catalog_audit_scan_has_the_required_nightly_schedule_contract(): void
    {
        $path = app_path('Console/Kernel.php');
        $source = file_get_contents($path);

        $this->assertNotFalse($source);
        $this->assertStringContainsString(<<<'PHP'
        $schedule->command('catalog-audit:scan')
            ->dailyAt('01:00')
            ->timezone('Europe/Bucharest')
            ->withoutOverlapping()
            ->runInBackground();
PHP, $source);
    }

    public function test_existing_midnight_missing_images_schedule_remains_unchanged(): void
    {
        $source = file_get_contents(app_path('Console/Kernel.php'));

        $this->assertNotFalse($source);
        $this->assertStringContainsString(<<<'PHP'
        $schedule->command('shopify:bulk-missing-images --send-minicrm')
            ->dailyAt('00:00')
            ->timezone('Europe/Bucharest')
            ->withoutOverlapping()
            ->runInBackground();
PHP, $source);
    }
}
