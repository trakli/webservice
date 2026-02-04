<?php

namespace App\Console\Commands;

use App\Services\InactivityService;
use Illuminate\Console\Command;

class SendInactivityReminders extends Command
{
    protected $signature = 'engagement:send-inactivity-reminders';

    protected $description = 'Send reminder emails to users who have been inactive';

    public function handle(InactivityService $service): void
    {
        $this->info('Checking for inactive users...');

        try {
            $sent = $service->sendInactivityReminders();
            $this->info("Sent reminders to {$sent} users.");
        } catch (\Throwable $e) {
            $this->error('Error sending inactivity reminders: ' . $e->getMessage());
            logger()->error('Inactivity reminders failed', ['error' => $e->getMessage()]);
        }
    }
}
