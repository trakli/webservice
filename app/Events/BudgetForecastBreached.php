<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BudgetForecastBreached
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $budgetId,
        public readonly string $periodStart,
        public readonly float $projectedSpend,
    ) {
    }
}
