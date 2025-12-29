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
        if ($transaction->wasChanged(['amount', 'type', 'wallet_id'])) {
            $originalWalletId = $transaction->getOriginal('wallet_id');
            $originalAmount = $transaction->getOriginal('amount');
            $originalType = $transaction->getOriginal('type');
            $originalTransferId = $transaction->getOriginal('transfer_id');

            // Revert original transaction's effect on the original wallet
            if ($originalWalletId && is_null($originalTransferId)) {
                $originalWallet = \App\Models\Wallet::find($originalWalletId);
                if ($originalWallet) {
                    $originalWallet->balance += ($originalType === 'expense') ? $originalAmount : -$originalAmount;
                    $originalWallet->save();
                }
            }

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
