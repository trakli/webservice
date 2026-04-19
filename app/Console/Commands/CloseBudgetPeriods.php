<?php

namespace App\Console\Commands;

use App\Jobs\CloseBudgetPeriodJob;
use App\Models\Budget;
use App\Services\BudgetProgressService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class CloseBudgetPeriods extends Command
{
    protected $signature = 'budgets:close-periods';

    protected $description = 'Close expired budget periods with rollover enabled and seed the next window';

    public function handle(BudgetProgressService $service): void
    {
        $this->info('Closing expired budget periods...');

        $now = CarbonImmutable::now();
        $queued = 0;

        Budget::query()
            ->where('rollover_enabled', true)
            ->where('is_active', true)
            ->chunkById(100, function ($budgets) use ($service, $now, &$queued) {
                foreach ($budgets as $budget) {
                    [, $periodEnd] = $service->resolvePeriodWindow($budget, $now->subDay());
                    if ($periodEnd->lt($now)) {
                        CloseBudgetPeriodJob::dispatch($budget->id);
                        $queued++;
                    }
                }
            });

        $this->info("Queued {$queued} budget period close jobs.");
    }
}
