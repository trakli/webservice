<?php

namespace App\Traits;

use App\Models\Refund;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Applied to Transaction. Lets an income transaction be flagged as a
 * refund (optionally linked to the original expense), and lets an
 * expense transaction see the refunds that were logged against it.
 *
 * This is the explicit alternative to guessing from category overlap —
 * the budget service consults the `refund` relation directly, so only
 * user-marked refunds reduce net spend.
 */
trait Refundable
{
    /** Refund row where THIS transaction is the refund. */
    public function refund(): HasOne
    {
        return $this->hasOne(Refund::class, 'refund_transaction_id');
    }

    /** Refunds that have been logged against THIS transaction (the original expense). */
    public function refundedBy(): HasMany
    {
        return $this->hasMany(Refund::class, 'original_transaction_id');
    }

    public function isRefund(): bool
    {
        return $this->refund()->exists();
    }

    /**
     * Mark this (income) transaction as a refund, optionally linking to
     * the original expense it reversed. Idempotent — re-marking just
     * updates the link.
     */
    public function markAsRefund(?Transaction $original = null): Refund
    {
        return $this->refund()->updateOrCreate(
            ['refund_transaction_id' => $this->id],
            ['original_transaction_id' => $original?->id]
        );
    }

    public function unmarkRefund(): void
    {
        $this->refund()->delete();
    }
}
