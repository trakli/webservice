<?php

namespace App\Console\Commands;

use App\Models\RecurringTransactionRule;
use App\Services\RecurringTransactionService;
use Illuminate\Console\Command;

class ProcessRecurringTransactions extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'transactions:process-recurring';

    /**
     * The console command description.
     */
    protected $description = 'Process and schedule recurring transactions that are due';

    /**
     * Execute the console command.
     */
    public function handle(RecurringTransactionService $service): void
    {
        $this->info('Starting to process recurring transactions ...');

        try {
            // call the service to process all transactions that are due
            // .1 Find all rules that are due and active
            $rules = RecurringTransactionRule::where('next_scheduled_at', '<=', now())
                ->where(function ($query) {
                    $query->where('recurrence_ends_at', '>=', now())
                        ->orWhereNull('recurrence_ends_at');
                })
                ->get();

            // 2. for each due rule
            foreach ($rules as $rule) {
                $service->createTransactionFromRule($rule);
                logger()->info('Dispatched job for rule ' . $rule->id);
            }

            $this->info('Recurring transactions processing completed.');
        } catch (\Throwable $e) {
            $this->error('Error processing recurring transactions: ' . $e->getMessage());
        }
    }
}
