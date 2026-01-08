<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('reminders:process')->everyMinute();

        $schedule->command('insights:send --frequency=weekly')
            ->weeklyOn(1, '08:00')
            ->withoutOverlapping();

        $schedule->command('insights:send --frequency=monthly')
            ->monthlyOn(1, '08:00')
            ->withoutOverlapping();

        $schedule->command('engagement:send-inactivity-reminders')
            ->dailyAt('10:00')
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
