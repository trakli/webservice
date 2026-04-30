<?php

namespace App\Observers;

use App\Events\TransactionRecorded;
use App\Events\TransactionSnapshot;
use App\Models\Transaction;
use App\Services\StatsService;
use Carbon\CarbonImmutable;

class TransactionObserver
{
    public function created(Transaction $transaction): void
    {
        $this->updateWalletBalance($transaction);
        $this->invalidateStatsCache($transaction);
        $this->emitRecorded($transaction, action: 'created');
    }

    public function updated(Transaction $transaction): void
    {
        $before = $this->originalSnapshot($transaction);

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
        $this->emitRecorded($transaction, action: 'updated', before: $before);
    }

    public function deleted(Transaction $transaction): void
    {
        $before = $this->originalSnapshot($transaction, useCurrent: true);
        $this->revertTransaction($transaction);
        $this->invalidateStatsCache($transaction);
        $this->emitRecorded($transaction, action: 'deleted', before: $before, afterSnapshotAllowed: false);
    }

    protected function invalidateStatsCache(Transaction $transaction): void
    {
        if ($transaction->user_id) {
            StatsService::invalidateUserCache($transaction->user_id);
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

    protected function emitRecorded(
        Transaction $transaction,
        string $action,
        ?TransactionSnapshot $before = null,
        bool $afterSnapshotAllowed = true,
    ): void {
        if (! $transaction->user_id) {
            return;
        }

        $after = $afterSnapshotAllowed ? TransactionRecorded::snapshot($transaction) : null;

        TransactionRecorded::dispatch($transaction->user_id, $action, $before, $after);
    }

    /**
     * Build a snapshot from getOriginal() values so we capture pre-update state.
     * On deletes the original and current are identical, so $useCurrent lets us
     * just reuse the current row values.
     */
    protected function originalSnapshot(Transaction $transaction, bool $useCurrent = false): ?TransactionSnapshot
    {
        $userId = $useCurrent ? $transaction->user_id : $transaction->getOriginal('user_id');
        if (! $userId) {
            return null;
        }

        $datetimeRaw = $useCurrent ? $transaction->datetime : $transaction->getOriginal('datetime');
        $datetime = null;
        if ($datetimeRaw) {
            $datetime = $datetimeRaw instanceof \DateTimeInterface
                ? CarbonImmutable::instance($datetimeRaw)
                : CarbonImmutable::parse($datetimeRaw);
        }

        return new TransactionSnapshot(
            transactionId: $transaction->id,
            userId: $userId,
            walletId: $useCurrent ? $transaction->wallet_id : $transaction->getOriginal('wallet_id'),
            type: TransactionRecorded::normalizeType(
                $useCurrent ? $transaction->type : $transaction->getOriginal('type')
            ),
            amount: (float) ($useCurrent ? $transaction->amount : $transaction->getOriginal('amount')),
            datetime: $datetime,
            categoryIds: $transaction->categories()->pluck('categories.id')->all(),
            groupIds: $transaction->groups()->pluck('groups.id')->all(),
        );
    }
}
