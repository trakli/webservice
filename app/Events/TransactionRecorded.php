<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionRecorded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $action,
        public readonly ?TransactionSnapshot $before = null,
        public readonly ?TransactionSnapshot $after = null,
    ) {
    }

    public static function snapshot(Transaction $transaction): TransactionSnapshot
    {
        return new TransactionSnapshot(
            transactionId: $transaction->id,
            userId: $transaction->user_id,
            walletId: $transaction->wallet_id,
            type: self::normalizeType($transaction->type),
            amount: (float) $transaction->amount,
            datetime: $transaction->datetime?->toImmutable(),
            categoryIds: $transaction->categories()->pluck('categories.id')->all(),
            groupIds: $transaction->groups()->pluck('groups.id')->all(),
        );
    }

    /**
     * The Transaction factory may hand us either a string or a backed
     * enum case for `type`; pin it to the scalar so downstream consumers
     * (including serialized queued listeners) get a stable shape.
     */
    public static function normalizeType(mixed $type): ?string
    {
        if ($type instanceof \BackedEnum) {
            return (string) $type->value;
        }

        return $type !== null ? (string) $type : null;
    }
}
