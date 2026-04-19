<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BudgetProgressRecomputed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $budgetId,
        public readonly string $periodStart,
        public readonly float $percentUsed,
        public readonly float $projectedSpend,
        public readonly bool $crossedThreshold,
        public readonly bool $crossedForecast,
    ) {
    }
}
