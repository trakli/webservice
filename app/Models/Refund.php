<?php

namespace App\Models;

use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OpenApi\Attributes as OA;
use Whilesmart\Agents\Contracts\HasAgentResource;
use Whilesmart\Agents\Resources\AgentResource;
use Whilesmart\Agents\Resources\ResourceField;
use Whilesmart\Agents\Resources\ResourceRelationship;
use Whilesmart\Agents\Resources\ThroughScope;

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
class Refund extends Model implements HasAgentResource
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

    public static function agentResource(): AgentResource
    {
        return new AgentResource(
            name: 'refunds',
            model: self::class,
            table: 'refunds',
            description: 'Money returned for an earlier expense, linking the incoming transaction to the one it reverses.',
            aliases: ['returns', 'reimbursements', 'money back'],
            ownerKey: null,
            scopeThrough: new ThroughScope(
                relation: 'refundTransaction',
                resource: 'transactions',
                column: 'refund_transaction_id',
                references: 'transactions.id',
            ),
            readable: [
                ResourceField::key(),
                ResourceField::reference('refund_transaction_id', 'transactions.id', 'The income transaction carrying the refunded money'),
                ResourceField::reference('original_transaction_id', 'transactions.id', 'The expense being refunded'),
                ResourceField::datetime('created_at', 'When the refund was recorded'),
            ],
            relationships: [
                ResourceRelationship::belongsTo('refund_transaction', 'transactions', 'refund_transaction_id'),
                ResourceRelationship::belongsTo('refunded_transaction', 'transactions', 'original_transaction_id'),
            ],
            writeEnabled: true,
        );
    }
}
