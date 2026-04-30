<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BudgetPeriodClosed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $budgetId,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly float $netSpent,
        public readonly float $rolloverOut,
    ) {
    }
}
