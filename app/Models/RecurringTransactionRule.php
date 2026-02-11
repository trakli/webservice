<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RecurringTransactionRule',
    properties: [
        new OA\Property(property: 'id', description: 'ID of the transaction', type: 'integer'),
        new OA\Property(property: 'transaction_id', description: 'ID of the transaction', type: 'integer'),
        new OA\Property(
            property: 'recurrence_period',
            description: 'Set how often the transaction should repeat',
            type: 'string'
        ),
        new OA\Property(
            property: 'recurrence_interval',
            description: 'Set how often the transaction should repeat',
            type: 'integer'
        ),
        new OA\Property(
            property: 'recurrence_ends_at',
            description: 'When the transaction stops repeating',
            type: 'string',
            format: 'date-time'
        ),
        new OA\Property(
            property: 'next_scheduled_at',
            description: 'when next the transaction should happen',
            type: 'string',
            format: 'date-time'
        ),
    ],
    type: 'object'
)]
class RecurringTransactionRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'recurrence_period',
        'recurrence_interval',
        'recurrence_ends_at',
        'transaction_id',
        'next_scheduled_at',
    ];

    protected $casts = [
        'next_scheduled_at' => 'datetime',
        'recurrence_ends_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
