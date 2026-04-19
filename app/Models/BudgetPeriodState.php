<?php

namespace App\Models;

use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OpenApi\Attributes as OA;

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
class BudgetPeriodState extends Model
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
}
