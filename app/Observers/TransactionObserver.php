<?php

namespace App\Observers;

use App\Http\Controllers\API\v1\StatsController;
use App\Models\Transaction;

class TransactionObserver
{
    public function created(Transaction $transaction): void
    {
        $this->updateWalletBalance($transaction);
        $this->invalidateStatsCache($transaction);
    }

    public function updated(Transaction $transaction): void
    {
        if ($transaction->wasChanged(['amount', 'type'])) {
            $originalTransaction = new Transaction;
            $originalTransaction->exists = true;
            $originalTransaction->id = $transaction->id;
            $originalTransaction->amount = $transaction->getOriginal('amount');
            $originalTransaction->type = $transaction->getOriginal('type');
            $originalTransaction->wallet_id = $transaction->getOriginal('wallet_id');
            $originalTransaction->transfer_id = $transaction->getOriginal('transfer_id');
            $originalTransaction->setRelation('wallet', $transaction->wallet);

            $this->revertTransaction($originalTransaction);
            $this->updateWalletBalance($transaction);
        }

        $this->invalidateStatsCache($transaction);
    }

    public function deleted(Transaction $transaction): void
    {
        $this->revertTransaction($transaction);
        $this->invalidateStatsCache($transaction);
    }

    protected function invalidateStatsCache(Transaction $transaction): void
    {
        if ($transaction->user_id) {
            StatsController::invalidateUserCache($transaction->user_id);
        }
    }

    protected function updateWalletBalance(Transaction $transaction): void
    {
        if (! $transaction->wallet || ! is_null($transaction->transfer_id)) {
            return;
        }

        $wallet = $transaction->wallet;
        $amount = $transaction->amount;

        $wallet->balance += ($transaction->type === 'expense') ? -$amount : $amount;
        $wallet->save();
    }

    protected function revertTransaction(Transaction $transaction): void
    {
        if (! $transaction->wallet || ! is_null($transaction->transfer_id)) {
            return;
        }

        $wallet = $transaction->wallet;
        $amount = $transaction->amount;

        $wallet->balance += ($transaction->type === 'expense') ? $amount : -$amount;
        $wallet->save();
    }
}
