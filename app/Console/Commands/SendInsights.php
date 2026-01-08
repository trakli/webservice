<?php

namespace App\Console\Commands;

use App\Services\InsightsService;
use Illuminate\Console\Command;

class SendInsights extends Command
{
    protected $signature = 'insights:send {--frequency=weekly : Filter by frequency (weekly/monthly)}';

    protected $description = 'Send financial insights email to users based on their preferences';

    public function handle(InsightsService $service): void
    {
        $frequency = $this->option('frequency');
        $this->info("Sending {$frequency} insights...");

        try {
            $sent = $service->sendInsights($frequency);
            $this->info("Sent insights to {$sent} users.");
        } catch (\Throwable $e) {
            $this->error('Error sending insights: '.$e->getMessage());
            logger()->error('Insights sending failed', ['error' => $e->getMessage()]);
        }
    }
}
