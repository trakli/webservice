<?php

namespace App\Services;

use App\Contracts\OwnerResolver;
use App\Models\Budget;
use App\Models\BudgetPeriodState;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class BudgetProgressService
{
    public const STATUS_ON_TRACK = 'on_track';

    public const STATUS_NEAR_LIMIT = 'near_limit';

    public const STATUS_OVER_BUDGET = 'over_budget';

    public const STATUS_FORECAST_BREACH = 'forecast_breach';

    public function __construct(
        protected OwnerResolver $ownerResolver,
        protected ExchangeRateService $exchangeRateService
    ) {
    }

    /**
     * Compute the current-period progress snapshot for a budget.
     * Pure read — persists nothing. Period states are only written by
     * CloseBudgetPeriodJob when rollover is enabled.
     *
     * @return array{
     *   period_start: string,
     *   period_end: string,
     *   limit: float,
     *   gross_spent: float,
     *   refunds: float,
     *   net_spent: float,
     *   rollover_in: float,
     *   effective_limit: float,
     *   remaining: float,
     *   percent_used: float,
     *   projected_spend: float,
     *   status: string,
     *   is_threshold_crossed: bool,
     *   is_forecast_breach: bool
     * }
     */
    public function compute(Budget $budget, ?CarbonInterface $referenceDate = null): array
    {
        $reference = $referenceDate
            ? CarbonImmutable::instance($referenceDate)
            : CarbonImmutable::now();

        [$periodStart, $periodEnd] = $this->resolvePeriodWindow($budget, $reference);

        $userIds = $this->ownerResolver->resolveUserIds($budget->owner);
        $categoryIds = $this->targetIds($budget, 'categories');
        $groupIds = $this->targetIds($budget, 'groups');
        $walletIds = $this->targetIds($budget, 'wallets');

        $limit = (float) $budget->amount;

        if (empty($userIds)) {
            return $this->emptyProgress($periodStart, $periodEnd, $limit, 0.0);
        }
        // NOTE: empty target lists are intentional — they mean the budget
        // covers every transaction in the owner's period, not zero.

        $grossSpent = $this->sumByType($userIds, $categoryIds, $groupIds, $walletIds, $periodStart, $periodEnd, 'expense', $budget->currency);

        // Only transactions the user has explicitly marked as refunds
        // (via the `refunds` table) count against the budget — no more
        // guessing from category overlap. See App\Traits\Refundable.
        $refunds = $this->sumByType(
            $userIds,
            $categoryIds,
            $groupIds,
            $walletIds,
            $periodStart,
            $periodEnd,
            'income',
            budgetCurrency: $budget->currency,
            onlyRefunds: true,
        );

        $netSpent = max(0.0, $grossSpent - $refunds);

        $rolloverIn = 0.0;
        if ($budget->rollover_enabled) {
            $previousState = BudgetPeriodState::query()
                ->where('budget_id', $budget->id)
                ->where('period_end', '<', $periodStart->toDateString())
                ->whereNotNull('closed_at')
                ->orderByDesc('period_end')
                ->first();
            $rolloverIn = $previousState ? (float) $previousState->rollover_out : 0.0;
        }

        $effectiveLimit = $limit + $rolloverIn;
        $remaining = $effectiveLimit - $netSpent;

        if ($effectiveLimit > 0) {
            $percentUsed = min(999.0, ($netSpent / $effectiveLimit) * 100.0);
        } elseif ($netSpent > 0) {
            // Zero-limit budget with spending is, by definition, over. The
            // alternative of reporting 0% hides the breach from status
            // logic downstream.
            $percentUsed = 100.0;
        } else {
            $percentUsed = 0.0;
        }

        $projectedSpend = $this->projectSpend($netSpent, $periodStart, $periodEnd, $reference);

        $thresholdCrossed = $budget->threshold_percent > 0
            && $percentUsed >= (float) $budget->threshold_percent;
        $forecastBreach = $budget->forecast_alerts_enabled
            && $percentUsed >= 50.0
            && $projectedSpend > $effectiveLimit;

        $status = $this->deriveStatus($netSpent, $effectiveLimit, $thresholdCrossed, $forecastBreach);

        return [
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'limit' => $limit,
            'gross_spent' => round($grossSpent, 4),
            'refunds' => round($refunds, 4),
            'net_spent' => round($netSpent, 4),
            'rollover_in' => round($rolloverIn, 4),
            'effective_limit' => round($effectiveLimit, 4),
            'remaining' => round($remaining, 4),
            'percent_used' => round($percentUsed, 2),
            'projected_spend' => round($projectedSpend, 4),
            'status' => $status,
            'is_threshold_crossed' => $thresholdCrossed,
            'is_forecast_breach' => $forecastBreach,
        ];
    }

    /**
     * Compute the current period window for a budget given a reference date.
     *
     * Preset periods use calendar boundaries — monthly = 1st → last day of
     * the month, weekly = Monday → Sunday, yearly = Jan 1 → Dec 31 — so
     * spending already recorded this period is counted the moment a user
     * creates the budget, matching most people's mental model of "monthly".
     *
     * `start_date` acts as the earliest valid period boundary: when the
     * reference date is before the budget began, we report the first
     * upcoming calendar window instead of a past one.
     *
     * Custom-period budgets use `start_date`/`end_date` literally.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function resolvePeriodWindow(Budget $budget, ?CarbonInterface $referenceDate = null): array
    {
        $reference = $referenceDate
            ? CarbonImmutable::instance($referenceDate)->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $start = CarbonImmutable::instance($budget->start_date)->startOfDay();

        if ($budget->period_type === Budget::PERIOD_CUSTOM) {
            return [
                $start,
                $budget->end_date
                    ? CarbonImmutable::instance($budget->end_date)->endOfDay()
                    : $start->addYears(100)->endOfDay(),
            ];
        }

        $windowFor = $reference->lt($start) ? $start : $reference;

        return match ($budget->period_type) {
            Budget::PERIOD_WEEKLY => [
                $windowFor->startOfWeek(CarbonImmutable::MONDAY),
                $windowFor->endOfWeek(CarbonImmutable::SUNDAY),
            ],
            Budget::PERIOD_MONTHLY => [
                $windowFor->startOfMonth(),
                $windowFor->endOfMonth(),
            ],
            Budget::PERIOD_YEARLY => [
                $windowFor->startOfYear(),
                $windowFor->endOfYear(),
            ],
        };
    }

    /**
     * Sum transactions of the given type matching the budget's owner +
     * target scope within the period window. When no targets are attached,
     * the budget covers every transaction — lets users set an overall
     * spending cap without enumerating categories.
     *
     * Transfers (the paired debit/credit rows that record moving money
     * between two wallets) are excluded — they aren't real spend or income
     * and would otherwise inflate refunds and zero out the budget.
     */
    protected function sumByType(
        array $userIds,
        array $categoryIds,
        array $groupIds,
        array $walletIds,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        string $type,
        string $budgetCurrency,
        bool $onlyRefunds = false,
    ): float {
        $query = Transaction::query()
            ->whereIn('user_id', $userIds)
            ->whereNull('transfer_id')
            ->where('type', $type)
            ->whereBetween('datetime', [$periodStart, $periodEnd]);

        if ($onlyRefunds) {
            $query->whereHas('refund');
        }

        if (empty($categoryIds) && empty($groupIds) && empty($walletIds)) {
            return $this->getReducedSum($query, $budgetCurrency);
        }

        $query->where(function (Builder $outer) use ($categoryIds, $groupIds, $walletIds) {
            if (! empty($categoryIds)) {
                $outer->orWhereHas('categories', function (Builder $q) use ($categoryIds) {
                    $q->whereIn('categories.id', $categoryIds);
                });
            }
            if (! empty($groupIds)) {
                $outer->orWhereHas('groups', function (Builder $q) use ($groupIds) {
                    $q->whereIn('groups.id', $groupIds);
                });
            }
            if (! empty($walletIds)) {
                $outer->orWhereIn('wallet_id', $walletIds);
            }
        });

        return $this->getReducedSum($query, $budgetCurrency);
    }

    /**
     * Calculates the total transaction amount, taking into account different currencies
    */
    private function getReducedSum($query, $baseCurrency): float
    {
        return (float) $query->with('wallet')->cursor()->reduce(function ($carry, Transaction $transaction) use ($baseCurrency) {
            $amount = $transaction->amount;

            if ($transaction->wallet->currency != $baseCurrency) {
                $exchangeRate = $this->exchangeRateService->getRate($baseCurrency, $transaction->wallet->currency);
                $amount = $transaction->amount * $exchangeRate;
            }

            return $amount + $carry;
        }, 0);
    }

    /**
     * Linear projection: netSpent × (totalDays / elapsed). The first few
     * days of a period are too noisy — a single large early transaction
     * can multiply into an unrealistic projection and false-flag a
     * forecast breach. Below a three-day floor we return netSpent
     * directly, which lets status logic still trigger `over_budget` on
     * real overspend but never `forecast_breach` prematurely.
     */
    protected const PROJECTION_MIN_ELAPSED_DAYS = 3;

    protected function projectSpend(
        float $netSpent,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        CarbonImmutable $reference,
    ): float {
        if ($reference->lt($periodStart)) {
            return 0.0;
        }

        $totalDays = max(1, $periodStart->diffInDays($periodEnd) + 1);
        $elapsed = max(1, $periodStart->diffInDays(min($reference, $periodEnd)) + 1);

        if ($elapsed < self::PROJECTION_MIN_ELAPSED_DAYS) {
            return $netSpent;
        }

        return $netSpent * ($totalDays / $elapsed);
    }

    protected function deriveStatus(
        float $netSpent,
        float $effectiveLimit,
        bool $thresholdCrossed,
        bool $forecastBreach,
    ): string {
        if ($effectiveLimit <= 0 ? $netSpent > 0 : $netSpent > $effectiveLimit) {
            return self::STATUS_OVER_BUDGET;
        }
        if ($forecastBreach) {
            return self::STATUS_FORECAST_BREACH;
        }
        if ($thresholdCrossed) {
            return self::STATUS_NEAR_LIMIT;
        }

        return self::STATUS_ON_TRACK;
    }

    /**
     * Pull target IDs without re-querying when the caller (typically
     * BudgetController::index) has already eager-loaded the relation.
     * Callers that pass a single Budget with lazy relations still work
     * — they just pay the three usual queries.
     */
    protected function targetIds(Budget $budget, string $relation): array
    {
        if ($budget->relationLoaded($relation)) {
            return $budget->{$relation}->pluck('id')->all();
        }

        $table = $budget->{$relation}()->getRelated()->getTable();

        return $budget->{$relation}()->pluck($table . '.id')->all();
    }

    protected function emptyProgress(CarbonImmutable $periodStart, CarbonImmutable $periodEnd, float $limit, float $rolloverIn): array
    {
        return [
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'limit' => $limit,
            'gross_spent' => 0.0,
            'refunds' => 0.0,
            'net_spent' => 0.0,
            'rollover_in' => $rolloverIn,
            'effective_limit' => $limit + $rolloverIn,
            'remaining' => $limit + $rolloverIn,
            'percent_used' => 0.0,
            'projected_spend' => 0.0,
            'status' => self::STATUS_ON_TRACK,
            'is_threshold_crossed' => false,
            'is_forecast_breach' => false,
        ];
    }
}
