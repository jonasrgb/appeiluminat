<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Jobs\RunCustomScript;
use App\Jobs\RunCustomScript2;
use App\Jobs\RunCustomScript3;
use App\Jobs\RunCustomScript4;

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
        $schedule->job(new RunCustomScript4)->dailyAt('00:00');
        //$schedule->command('app:check-products-count')->sundays()->at('00:00');
        $schedule->command('emails:sync-inbox-raw')
        ->everyFiveMinutes()  
        ->withoutOverlapping();  
        $schedule->command('emails:sync-inbox-raw-powerleds')
        ->everyFiveMinutes()  
        ->withoutOverlapping();
        $schedule->command('emails:sync-inbox-raw-industrial')
        ->everyFiveMinutes()  
        ->withoutOverlapping();  
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
