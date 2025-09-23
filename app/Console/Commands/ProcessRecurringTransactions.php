<?php

namespace App\Console\Commands;

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
            $service->processAllDueTransactions();
            $this->info('Recurring transactions processing completed.');
        } catch (\Exception $e) {
            $this->error('Error processing recurring transactions: '.$e->getMessage());
        }
    }
}
