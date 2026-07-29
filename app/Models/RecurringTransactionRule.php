<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OpenApi\Attributes as OA;
use Whilesmart\Agents\Contracts\HasAgentResource;
use Whilesmart\Agents\Resources\AgentResource;
use Whilesmart\Agents\Resources\ResourceField;
use Whilesmart\Agents\Resources\ResourceRelationship;
use Whilesmart\Agents\Resources\ThroughScope;

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
class RecurringTransactionRule extends Model implements HasAgentResource
{
    use HasFactory;

    public const RECURRENCE_PERIODS = ['daily', 'weekly', 'monthly', 'yearly'];

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

    public static function agentResource(): AgentResource
    {
        return new AgentResource(
            name: 'recurring_rules',
            model: self::class,
            table: 'recurring_transaction_rules',
            description: 'Rules that repeat a transaction on a schedule (rent, salary, subscriptions).',
            aliases: ['recurring transactions', 'repeats', 'subscriptions', 'standing orders'],
            ownerKey: null,
            scopeThrough: new ThroughScope(
                relation: 'transaction',
                resource: 'transactions',
                column: 'transaction_id',
                references: 'transactions.id',
            ),
            readable: [
                ResourceField::key(),
                ResourceField::reference('transaction_id', 'transactions.id', 'The transaction being repeated'),
                ResourceField::enum('recurrence_period', self::RECURRENCE_PERIODS, 'Unit the rule repeats on'),
                ResourceField::integer('recurrence_interval', 'How many periods between occurrences'),
                ResourceField::datetime('next_scheduled_at', 'When the next occurrence is due'),
                ResourceField::datetime('recurrence_ends_at', 'When the rule stops repeating'),
            ],
            relationships: [
                ResourceRelationship::belongsTo('recurring_rule_transaction', 'transactions', 'transaction_id'),
            ],
            writeEnabled: true,
            orderColumn: 'next_scheduled_at',
        );
    }
}
