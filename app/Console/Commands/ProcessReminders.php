<?php

namespace App\Console\Commands;

use App\Services\ReminderService;
use Illuminate\Console\Command;

class ProcessReminders extends Command
{
    protected $signature = 'reminders:process';

    protected $description = 'Process due reminders and send notifications';

    public function handle(ReminderService $service): void
    {
        $this->info('Processing due reminders...');

        try {
            $processed = $service->processDueReminders();
            $this->info("Processed {$processed} reminders.");
        } catch (\Throwable $e) {
            $this->error('Error processing reminders: '.$e->getMessage());
            logger()->error('Reminder processing failed', ['error' => $e->getMessage()]);
        }
    }
}
