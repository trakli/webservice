<?php

namespace App\Jobs;

use App\Services\RecurringTransactionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecurrentTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $ruleId;

    /**
     * The job only needs the ID of the rule to process.
     */
    public function __construct(int $ruleId)
    {
        $this->ruleId = $ruleId;
    }

    /**
     * The job's only purpose is to tell the service to do the work.
     */
    public function handle(RecurringTransactionService $service): void
    {
        logger()->info('Handling recurrent transaction for rule ID: ' . $this->ruleId);

        try {
            // Call the service to do all the work.
            $service->generateNextTransaction($this->ruleId);
        } catch (\Exception $e) {
            logger()->error('Error processing job for rule ' . $this->ruleId . ': ' . $e->getMessage());
        }
    }
}
