<?php

namespace App\Console\Commands;

use App\Jobs\SendBudgetWeeklyDigestJob;
use App\Models\User;
use Illuminate\Console\Command;

class SendBudgetDigests extends Command
{
    protected $signature = 'budgets:send-digests';

    protected $description = 'Queue the weekly budget digest for every user with at least one active budget';

    public function handle(): void
    {
        $queued = 0;

        User::query()
            ->whereHas('budgets', fn ($q) => $q->where('is_active', true))
            ->select('id')
            ->chunkById(200, function ($users) use (&$queued) {
                foreach ($users as $user) {
                    SendBudgetWeeklyDigestJob::dispatch($user->id);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} budget digest jobs.");
    }
}
