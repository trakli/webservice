<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Model;

class TransferService
{
    public function transfer(float $amountToSend, Model $fromWallet, float $amountToReceive, Model $toWallet, User $user, float $exchangeRate)
    {
        if ($fromWallet->balance < $amountToSend) {
            throw new \InvalidArgumentException(__('Insufficient balance in source wallet'));
        }
        // create transfer
        $transfer = Transfer::create([
            'amount' => $amountToSend,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'user_id' => $user->id,
            'exchange_rate' => $exchangeRate,
        ]);

        // deduct from source wallet
        $fromWallet->balance -= $amountToSend;
        $fromWallet->save();

        $user->transactions()->create([
            'amount' => $amountToSend,
            'datetime' => now(),
            'type' => TransactionType::EXPENSE->value,
            'description' => "Money transfer from $fromWallet->currency wallet to $toWallet->currency wallet",
            'wallet_id' => $fromWallet->id,
            'transfer_id' => $transfer->id,
        ]);

        // send  to destination wallet
        $toWallet->balance = bcadd($toWallet->balance, $amountToReceive, 4);
        $toWallet->save();

        $user->transactions()->create([
            'amount' => $amountToReceive,
            'datetime' => now(),
            'type' => TransactionType::INCOME->value,
            'description' => "Money transfer from $fromWallet->currency wallet to $toWallet->currency wallet",
            'wallet_id' => $toWallet->id,
            'transfer_id' => $transfer->id,
        ]);

        return $transfer;
    }
}
