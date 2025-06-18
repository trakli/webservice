<?php

namespace App\Console\Commands;

use App\Enums\TransactionRecurringPeriod;
use App\Models\Transaction;
use Illuminate\Console\Command;

class GenerateRecurringTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transactions:generate-recurring';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command to generate recurring transactions';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        //todo: add logs
        logger()->info('Generating recurring transactions');
        $recurringTransactions = Transaction::where('is_recurring', true)
            ->where(function ($query) {
                $query->whereNull('recurrence_ends_at')
                    ->orWhere('recurrence_ends_at', '>=', now());
            })->get();

        logger()->info('found '.count($recurringTransactions).' recurring transactions');
        foreach ($recurringTransactions as $transaction) {
            // Get the last recurring transaction based on parent or self
            $lastTransaction = Transaction::where('parent_transaction_id', $transaction->id)
                ->orWhere('id', $transaction->id)
                ->orderBy('id', 'desc')
                ->first();

            if ($transaction->recurrence_period == null) {
                continue;
            }
            $nextDate = match (TransactionRecurringPeriod::from($transaction->recurrence_period)) {
                TransactionRecurringPeriod::DAILY => $lastTransaction->created_at->addDays($transaction->recurrence_interval),
                TransactionRecurringPeriod::WEEKLY => $lastTransaction->created_at->addWeeks($transaction->recurrence_interval),
                TransactionRecurringPeriod::MONTHLY => $lastTransaction->created_at->addMonths($transaction->recurrence_interval),
                TransactionRecurringPeriod::YEARLY => $lastTransaction->created_at->addYears($transaction->recurrence_interval),
            };

            if (now()->greaterThanOrEqualTo($nextDate) &&
                ($transaction->recurrence_ends_at === null || $nextDate <= $transaction->recurrence_ends_at)) {

                Transaction::create([
                    'amount' => $transaction->amount,
                    'datetime' => $nextDate,
                    'type' => $transaction->type,
                    'description' => $transaction->description,
                    'wallet_id' => $transaction->wallet_id,
                    'party_id' => $transaction->party_id,
                    'user_id' => $transaction->user_id,
                    'parent_transaction_id' => $transaction->id,
                ]);
            }
        }
    }
}
