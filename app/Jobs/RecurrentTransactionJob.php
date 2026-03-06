<?php

namespace App\Jobs;

use App\Models\RecurringTransactionRule;
use App\Services\RecurringTransactionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecurrentTransactionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

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
            if ($service->isRuleValid($this->ruleId)) {
                $rule = RecurringTransactionRule::with('transaction')->find($this->ruleId);
                // Call the service to do all the work.
                $service->createTransactionFromRule($rule);
            }
        } catch (\Throwable $e) {
            logger()->error('Error processing job for rule ' . $this->ruleId . ': ' . $e->getMessage());
        }
    }
}
