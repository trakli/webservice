<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Refund row marks an income transaction as refunding money received
 * back from a prior expense. The `original_transaction_id` link is
 * optional — users sometimes log a refund without remembering or caring
 * which specific expense it came from. See the `Refundable` trait on
 * Transaction for the query helpers.
 */
class Refund extends Model
{
    use HasFactory;

    protected $fillable = [
        'refund_transaction_id',
        'original_transaction_id',
    ];

    public function refundTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'refund_transaction_id');
    }

    public function originalTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'original_transaction_id');
    }
}
