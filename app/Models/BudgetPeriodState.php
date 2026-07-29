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

#[OA\Schema(
    schema: 'BudgetPeriodState',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'budget_id', type: 'integer'),
        new OA\Property(property: 'period_start', type: 'string', format: 'date'),
        new OA\Property(property: 'period_end', type: 'string', format: 'date'),
        new OA\Property(property: 'net_spent', type: 'number', format: 'float'),
        new OA\Property(property: 'rollover_in', type: 'number', format: 'float'),
        new OA\Property(property: 'rollover_out', type: 'number', format: 'float'),
        new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'client_generated_id', type: 'string', nullable: true),
        new OA\Property(property: 'last_synced_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
class BudgetPeriodState extends Model implements HasAgentResource
{
    use HasFactory;
    use Syncable;

    protected $fillable = [
        'budget_id',
        'period_start',
        'period_end',
        'net_spent',
        'rollover_in',
        'rollover_out',
        'closed_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'closed_at' => 'datetime',
        'net_spent' => 'decimal:4',
        'rollover_in' => 'decimal:4',
        'rollover_out' => 'decimal:4',
    ];

    protected $appends = [
        'client_generated_id',
        'last_synced_at',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public static function agentResource(): AgentResource
    {
        return new AgentResource(
            name: 'budget_period_states',
            model: self::class,
            table: 'budget_period_states',
            description: 'What a budget actually spent in a period once that period closed. Written by the system, never by the user.',
            aliases: ['budget history', 'budget periods'],
            ownerKey: null,
            scopeThrough: new ThroughScope(
                relation: 'budget',
                resource: 'budgets',
                column: 'budget_id',
                references: 'budgets.id',
            ),
            readable: [
                ResourceField::key(),
                ResourceField::reference('budget_id', 'budgets.id', 'Budget this period belongs to'),
                ResourceField::date('period_start', 'First day of the period'),
                ResourceField::date('period_end', 'Last day of the period'),
                ResourceField::decimal('net_spent', 'Spending in the period after refunds'),
                ResourceField::decimal('rollover_in', 'Unspent money carried in from the previous period'),
                ResourceField::decimal('rollover_out', 'Unspent money carried out to the next period'),
                ResourceField::datetime('closed_at', 'When the period was closed'),
            ],
            relationships: [
                ResourceRelationship::belongsTo('period_state_budget', 'budgets', 'budget_id'),
            ],
            orderColumn: 'period_start',
        );
    }
}
