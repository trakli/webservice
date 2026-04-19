<?php

namespace App\Models;

use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OpenApi\Attributes as OA;

/**
 * A Refund row marks an income transaction as refunding money received
 * back from a prior expense. The `original_transaction_id` link is
 * optional — users sometimes log a refund without remembering or caring
 * which specific expense it came from. See the `Refundable` trait on
 * Transaction for the query helpers.
 */
#[OA\Schema(
    schema: 'Refund',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'refund_transaction_id', description: 'The income transaction marked as a refund', type: 'integer'),
        new OA\Property(property: 'original_transaction_id', description: 'Optional link to the expense being refunded', type: 'integer', nullable: true),
        new OA\Property(property: 'client_generated_id', type: 'string', nullable: true),
        new OA\Property(property: 'last_synced_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
class Refund extends Model
{
    use HasFactory;
    use Syncable;

    protected $fillable = [
        'refund_transaction_id',
        'original_transaction_id',
    ];

    protected $appends = [
        'client_generated_id',
        'last_synced_at',
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
