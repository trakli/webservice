<?php

namespace App\Jobs;

use App\Events\BudgetForecastBreached;
use App\Events\BudgetProgressRecomputed;
use App\Events\BudgetThresholdBreached;
use App\Models\Budget;
use App\Services\BudgetProgressService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecomputeBudgetProgressJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Collapse duplicate dispatches within 30 seconds so a batched
     * transaction import doesn't run one job per row for the same budget.
     */
    public int $uniqueFor = 30;

    public function __construct(
        public readonly int $budgetId,
    ) {
    }

    public function uniqueId(): string
    {
        return 'budget:' . $this->budgetId;
    }

    public function handle(BudgetProgressService $service): void
    {
        $budget = Budget::query()->find($this->budgetId);
        if (! $budget) {
            return;
        }

        $progress = $service->compute($budget);

        BudgetProgressRecomputed::dispatch(
            $budget->id,
            $progress['period_start'],
            $progress['percent_used'],
            $progress['projected_spend'],
            $progress['is_threshold_crossed'],
            $progress['is_forecast_breach'],
        );

        if ($progress['is_threshold_crossed']) {
            BudgetThresholdBreached::dispatch(
                $budget->id,
                $progress['period_start'],
                $progress['percent_used'],
            );
        }

        if ($progress['is_forecast_breach']) {
            BudgetForecastBreached::dispatch(
                $budget->id,
                $progress['period_start'],
                $progress['projected_spend'],
            );
        }
    }
}
