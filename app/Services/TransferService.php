<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use App\Support\ConfigurationKeys;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

class TransferService
{
    /**
     * @param  array{expense_transaction_client_id?: string|null, income_transaction_client_id?: string|null}  $transactionClientIds
     */
    public function transfer(
        float $amountToSend,
        Wallet $fromWallet,
        float $amountToReceive,
        Wallet $toWallet,
        User $user,
        float $exchangeRate,
        ?string $deviceToken = null,
        ?string $datetime = null,
        array $transactionClientIds = []
    ) {
        if (
            $fromWallet->balance < $amountToSend
            && ! $user->getConfigValue(ConfigurationKeys::WALLETS_ALLOW_NEGATIVE_BALANCE, false)
        ) {
            throw new InvalidArgumentException(__('Insufficient balance in source wallet'));
        }

        $transferDatetime = $datetime ?? now();

        $transfer = Transfer::create([
            'amount' => $amountToSend,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'user_id' => $user->id,
            'exchange_rate' => $exchangeRate,
            'datetime' => $transferDatetime,
        ]);

        $fromWallet->balance -= $amountToSend;
        $fromWallet->save();

        $expenseTransaction = $this->findOrCreateTransaction(
            $transactionClientIds['expense_transaction_client_id'] ?? null,
            $user,
            [
                'amount' => $amountToSend,
                'datetime' => $transferDatetime,
                'type' => TransactionType::EXPENSE->value,
                'description' => "Money transfer from $fromWallet->currency wallet to $toWallet->currency wallet",
                'wallet_id' => $fromWallet->id,
                'transfer_id' => $transfer->id,
            ]
        );

        $toWallet->balance = bcadd($toWallet->balance, $amountToReceive, 4);
        $toWallet->save();

        $incomeTransaction = $this->findOrCreateTransaction(
            $transactionClientIds['income_transaction_client_id'] ?? null,
            $user,
            [
                'amount' => $amountToReceive,
                'datetime' => $transferDatetime,
                'type' => TransactionType::INCOME->value,
                'description' => "Money transfer from $fromWallet->currency wallet to $toWallet->currency wallet",
                'wallet_id' => $toWallet->id,
                'transfer_id' => $transfer->id,
            ]
        );

        if ($deviceToken) {
            if (! $expenseTransaction->client_generated_id) {
                $expenseRandomId = Uuid::uuid4()->toString();
                $expenseTransaction->setClientGeneratedId($expenseRandomId, $user, $deviceToken);
            }
            $expenseTransaction->markAsSynced();

            if (! $incomeTransaction->client_generated_id) {
                $incomeRandomId = Uuid::uuid4()->toString();
                $incomeTransaction->setClientGeneratedId($incomeRandomId, $user, $deviceToken);
            }
            $incomeTransaction->markAsSynced();
        }

        return $transfer;
    }

    private function findOrCreateTransaction(?string $clientId, User $user, array $data): Transaction
    {
        if ($clientId) {
            $existing = Transaction::findByClientId($clientId, $user);
            if ($existing) {
                $existing->update(['transfer_id' => $data['transfer_id']]);

                return $existing;
            }
        }

        return $user->transactions()->create($data);
    }
}
