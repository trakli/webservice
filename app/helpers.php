<?php

use App\Enums\TransactionRecurringPeriod;
use App\Models\RecurringTransactionRule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

if (! function_exists('format_iso8601_to_sql')) {
    function format_iso8601_to_sql(?string $iso8601): ?string
    {
        if (! $iso8601) {
            return null;
        }
        try {
            return Carbon::parse($iso8601)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }
}

function get_next_transaction_schedule_date(RecurringTransactionRule|Model $recurring_transaction): ?Carbon
{
    return match (TransactionRecurringPeriod::from($recurring_transaction->recurrence_period)) {
        TransactionRecurringPeriod::DAILY => now()->addDays($recurring_transaction->recurrence_interval),
        TransactionRecurringPeriod::WEEKLY => now()->addWeeks($recurring_transaction->recurrence_interval),
        TransactionRecurringPeriod::MONTHLY => now()->addMonths($recurring_transaction->recurrence_interval),
        TransactionRecurringPeriod::YEARLY => now()->addYears($recurring_transaction->recurrence_interval),
    };
}
