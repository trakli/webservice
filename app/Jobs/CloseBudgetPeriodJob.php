<?php

namespace App\Jobs;

use App\Events\BudgetPeriodClosed;
use App\Models\Budget;
use App\Models\BudgetPeriodState;
use App\Services\BudgetProgressService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class CloseBudgetPeriodJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $budgetId,
    ) {
    }

    public function handle(BudgetProgressService $service): void
    {
        $budget = Budget::query()->find($this->budgetId);
        if (! $budget || ! $budget->rollover_enabled) {
            return;
        }

        [$periodStart, $periodEnd] = $service->resolvePeriodWindow($budget, CarbonImmutable::now()->subDay());

        if ($periodEnd->isFuture()) {
            return;
        }

        $progress = $service->compute($budget, $periodEnd);

        $rolloverOut = max(-1.0 * (float) $budget->amount, (float) $progress['effective_limit'] - (float) $progress['net_spent']);

        DB::transaction(function () use ($budget, $periodStart, $periodEnd, $progress, $rolloverOut) {
            BudgetPeriodState::query()->updateOrCreate(
                [
                    'budget_id' => $budget->id,
                    'period_start' => $periodStart->toDateString(),
                ],
                [
                    'period_end' => $periodEnd->toDateString(),
                    'net_spent' => $progress['net_spent'],
                    'rollover_in' => $progress['rollover_in'],
                    'rollover_out' => $rolloverOut,
                    'closed_at' => now(),
                ]
            );
        });

        BudgetPeriodClosed::dispatch(
            $budget->id,
            $periodStart->toDateString(),
            $periodEnd->toDateString(),
            (float) $progress['net_spent'],
            $rolloverOut,
        );
    }
}
