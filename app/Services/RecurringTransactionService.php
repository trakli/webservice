<?php

namespace App\Services;

use App\Models\RecurringTransactionRule;
use App\Models\Transaction;
use Carbon\Carbon;

class RecurringTransactionService
{
    /**
     * This method is called by the job. It finds a specific recurring rule
     * and processes it.
     */
    public function generateNextTransaction(int $ruleId): void
    {
        // 1. Find the specific rule in the database by its ID.
        // It's linked to the original transaction.
        $rule = RecurringTransactionRule::with('transaction')->find($ruleId);

        // 2. If the rule doesn't exist, we stop here.
        if (! $rule) {
            logger()->info('Recurring transaction rule '.$ruleId.' not found.');

            return;
        }

        // 3. Make sure the rule is still active and hasn't expired.
        if ($rule->recurrence_ends_at && $rule->recurrence_ends_at < now()) {
            logger()->info('Rule '.$ruleId.' has expired. Skipping.');

            return;
        }

        try {
            // 4. Create a brand new transaction based on the rule.
            $this->createTransactionFromRule($rule);

            // 5. Calculate the next time this transaction should happen.
            $nextRunDate = $this->calculateNextRunDate($rule);

            // 6. Tell Laravel to run the job again at that future date.
            dispatch(new \App\Jobs\RecurrentTransactionJob($rule->id))->delay($nextRunDate);
            logger()->info('Scheduled next transaction for '.$rule->id.' at '.$nextRunDate);

        } catch (\Exception $e) {
            logger()->error('Error processing rule '.$ruleId.': '.$e->getMessage());
        }
    }

    /**
     * This method is called by the command. It finds ALL rules that are due
     * and dispatches a separate job for each one.
     */
    public function processAllDueTransactions(): void
    {
        // 1. Find all rules that are due to be run.
        $rules = RecurringTransactionRule::where('recurrence_ends_at', '>=', now())
            ->orWhereNull('recurrence_ends_at')
            ->get();

        // 2. For each rule, dispatch a job to handle it.
        foreach ($rules as $rule) {
            // Dispatch a job for each rule. This is better than doing all the
            // work at once, especially for many transactions.
            dispatch(new \App\Jobs\RecurrentTransactionJob($rule->id));
        }
    }

    /**
     * Creates a new transaction record from a recurring rule.
     */
    private function createTransactionFromRule(RecurringTransactionRule $rule): Transaction
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
        $newTransaction->markAsSynced();

        if ($originalTransaction->categories->isNotEmpty()) {
            $newTransaction->categories()->sync($originalTransaction->categories->pluck('id'));
        }

        return $newTransaction;
    }

    /**
     * Calculates the date of the next transaction.
     */
    private function calculateNextRunDate(RecurringTransactionRule $rule): Carbon
    {
        // Use a function that exists in your project to calculate the next date.
        return get_next_transaction_schedule_date($rule);
    }
}
