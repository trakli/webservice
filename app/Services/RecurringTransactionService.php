<?php

namespace App\Services;

use App\Enums\TransactionRecurringPeriod;
use App\Jobs\RecurrentTransactionJob;
use App\Models\RecurringTransactionRule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class RecurringTransactionService
{
    /**
     * Checks if a rule to be run is valid
     */
    public function isRuleValid(int $ruleId): bool
    {
        // 1. Find the specific rule in the database by its ID.
        // It's linked to the original transaction.
        $rule = RecurringTransactionRule::with('transaction')->find($ruleId);

        // 2. If the rule doesn't exist, we stop here.
        if (! $rule) {
            logger()->info('Recurring transaction rule ' . $ruleId . ' not found.');

            return false;
        }

        // 3. Make sure the rule is still active and hasn't expired.
        if ($rule->recurrence_ends_at && $rule->recurrence_ends_at < now()) {
            logger()->info('Rule ' . $ruleId . ' has expired. Skipping.');

            return false;
        }
        // 4. Check if the scheduled date matches today's date (YYYY-MM-DD only)
        if ($rule->next_scheduled_at->toDateString() !== now()->toDateString()) {
            logger()->info('Rule ' . $ruleId . ' scheduled date (' .
                $rule->next_scheduled_at->toDateString() .
                ') does not match current date. Skipping.');

            return false;
        }

        // 5. Check if the transaction is not deleted even though this might never happen
        if (! $rule->transaction) {
            logger()->info('Rule ' . $ruleId . ' does not have a transaction.');

            return false;
        }

        return true;
    }

    /**
     * Creates a new transaction record from a recurring rule.
     */
    public function createTransactionFromRule(RecurringTransactionRule $rule): void
    {
        // Get the original transaction data.
        $originalTransaction = $rule->transaction;

        // Make a copy of the original transaction.
        $newTransaction = $originalTransaction->replicate();

        // Update the copy with today's date and time.
        $newTransaction->created_at = now();
        $newTransaction->updated_at = now();

        // Save the new copy to the database and synced.
        $newTransaction->save();

        if ($originalTransaction->categories->isNotEmpty()) {
            $newTransaction->categories()->sync($originalTransaction->categories->pluck('id'));
        }

        // Schedule the next job
        $rule->next_scheduled_at = $this->getNextScheduleDate($rule);
        $rule->save();

        // 8. Dispatch next job with delay
        RecurrentTransactionJob::dispatch($rule->id)->delay($rule->next_scheduled_at);
        logger()->info('Scheduled next transaction for ' . $rule->transaction->id . ' at ' . $rule->next_scheduled_at);
    }

    public function getNextScheduleDate(RecurringTransactionRule|Model $recurringTransaction): ?Carbon
    {
        return match (TransactionRecurringPeriod::from($recurringTransaction->recurrence_period)) {
            TransactionRecurringPeriod::DAILY => $this->getLastScheduledDate($recurringTransaction)
                ->addDays($recurringTransaction->recurrence_interval),
            TransactionRecurringPeriod::WEEKLY => $this->getLastScheduledDate($recurringTransaction)
                ->addWeeks($recurringTransaction->recurrence_interval),
            TransactionRecurringPeriod::MONTHLY => $this->getLastScheduledDate($recurringTransaction)
                ->addMonths($recurringTransaction->recurrence_interval),
            TransactionRecurringPeriod::YEARLY => $this->getLastScheduledDate($recurringTransaction)
                ->addYears($recurringTransaction->recurrence_interval),
        };
    }

    private function getLastScheduledDate(RecurringTransactionRule|Model $recurringTransaction): Carbon
    {
        if (is_null($recurringTransaction->next_scheduled_at)) {
            return now();
        }

        return Carbon::parse($recurringTransaction->next_scheduled_at);
    }
}
