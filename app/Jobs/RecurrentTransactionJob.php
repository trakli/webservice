<?php

namespace App\Jobs;

use App\Models\RecurringTransactionRule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecurrentTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private int $id;

    private string $recurrence_period;

    private int $recurrence_interval;

    /**
     * Create a new job instance.
     */
    public function __construct(int $id, string $recurrence_period, int $recurrence_interval)
    {
        $this->id = $id;
        $this->recurrence_period = $recurrence_period;
        $this->recurrence_interval = $recurrence_interval;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            logger()->info('Handling recurrent transaction');
            // 1. Check if the user might have cancelled this recurring transaction
            $recurring_transaction = RecurringTransactionRule::find($this->id);
            if ($recurring_transaction) {
                // 2. Cancel this job if the recurrence period or recurrence interval has changed or  has exceeded the end date
                if (
                    ($this->recurrence_period == $recurring_transaction->recurrence_period)
                    &&
                    ($this->recurrence_interval == $recurring_transaction->recurrence_interval)
                    &&
                    ($recurring_transaction->recurrence_ends_at >= now() || is_null($recurring_transaction->recurrence_ends_at))
                ) {
                    logger()->info('Running recurrent transaction with id '.$this->id);
                    $new_transaction = $recurring_transaction->transaction->replicate();
                    $new_transaction->created_at = now();
                    $new_transaction->updated_at = now();
                    $new_transaction->save();

                    if ($recurring_transaction->transaction->categories->isNotEmpty()) {
                        $new_transaction->categories()->sync($recurring_transaction->transaction->categories->pluck('id'));
                    }

                    // 3. schedule next transaction
                    if ($recurring_transaction->recurrence_ends_at > now() || is_null($recurring_transaction->recurrence_ends_at)) {
                        $next_date = get_next_transaction_schedule_date($recurring_transaction);
                        logger()->info('Next transaction date for '.$this->id.": {$next_date}");
                        RecurrentTransactionJob::dispatch(
                            id: (int) $recurring_transaction->id,
                            recurrence_period: $recurring_transaction->recurrence_period,
                            recurrence_interval: $recurring_transaction->recurrence_interval
                        )->delay($next_date);
                    }
                } else {
                    logger()->info('Recurrent transaction details for '.$this->id.' have changed, skipping');
                }
            } else {
                logger()->info('Recurrent transaction for '.$this->id.' not found');
            }
        } catch (\Exception $e) {
            logger()->error('Exception occurred');
            logger()->error($e->getMessage());
        }
    }
}
