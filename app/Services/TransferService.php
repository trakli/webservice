<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use Ramsey\Uuid\Uuid;

class TransferService
{
    public function transfer(
        float $amountToSend,
        Wallet $fromWallet,
        float $amountToReceive,
        Wallet $toWallet,
        User $user,
        float $exchangeRate,
        ?string $deviceToken = null
    ) {
        if ($fromWallet->balance < $amountToSend) {
            throw new \InvalidArgumentException(__('Insufficient balance in source wallet'));
        }

        $transfer = Transfer::create([
            'amount' => $amountToSend,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'user_id' => $user->id,
            'exchange_rate' => $exchangeRate,
        ]);

        $fromWallet->balance -= $amountToSend;
        $fromWallet->save();

        $expenseTransaction = $user->transactions()->create([
            'amount' => $amountToSend,
            'datetime' => now(),
            'type' => TransactionType::EXPENSE->value,
            'description' => "Money transfer from $fromWallet->currency wallet to $toWallet->currency wallet",
            'wallet_id' => $fromWallet->id,
            'transfer_id' => $transfer->id,
        ]);

        $toWallet->balance = bcadd($toWallet->balance, $amountToReceive, 4);
        $toWallet->save();

        $incomeTransaction = $user->transactions()->create([
            'amount' => $amountToReceive,
            'datetime' => now(),
            'type' => TransactionType::INCOME->value,
            'description' => "Money transfer from $fromWallet->currency wallet to $toWallet->currency wallet",
            'wallet_id' => $toWallet->id,
            'transfer_id' => $transfer->id,
        ]);

        if ($deviceToken) {
            $expenseRandomId = Uuid::uuid4()->toString();
            $expenseTransaction->setClientGeneratedId($expenseRandomId, $user, $deviceToken);
            $expenseTransaction->markAsSynced();

            $incomeRandomId = Uuid::uuid4()->toString();
            $incomeTransaction->setClientGeneratedId($incomeRandomId, $user, $deviceToken);
            $incomeTransaction->markAsSynced();
        }

        return $transfer;
    }
}
