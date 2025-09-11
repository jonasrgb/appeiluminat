<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Jobs\RunCustomScript;
use App\Jobs\RunCustomScript2;
use App\Jobs\RunCustomScript3;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();
        $schedule->job(new RunCustomScript)->dailyAt('00:00');
        $schedule->job(new RunCustomScript2)->dailyAt('00:00');
        $schedule->job(new RunCustomScript3)->dailyAt('00:00');
        $schedule->command('app:check-products-count')->sundays()->at('00:00');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        \App\Console\Commands\ShopsAdd::class;
        \App\Console\Commands\ShopsConnect::class;
        require base_path('routes/console.php');
    }
}
