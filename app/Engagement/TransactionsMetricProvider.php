<?php

namespace App\Engagement;

use App\Engagement\Concerns\SeriesFromDates;
use App\Models\Transaction;
use Whilesmart\Engagement\Contracts\MetricProvider;
use Whilesmart\Engagement\Support\Metric;
use Whilesmart\Engagement\Support\Period;

class TransactionsMetricProvider implements MetricProvider
{
    use SeriesFromDates;

    public function key(): string
    {
        return 'transactions';
    }

    public function label(): string
    {
        return 'Transactions';
    }

    public function metrics(Period $period): array
    {
        $base = Transaction::query()->whereBetween('datetime', [$period->start, $period->end]);
        $dates = (clone $base)->pluck('datetime');

        return [
            Metric::count('total_transactions', 'Transactions', $dates->count()),
            Metric::count('income_count', 'Income entries', (clone $base)->where('type', 'income')->count()),
            Metric::count('expense_count', 'Expense entries', (clone $base)->where('type', 'expense')->count()),
            Metric::series('transactions_series', 'Transactions over time', $this->seriesRows($dates, $period)),
        ];
    }
}
