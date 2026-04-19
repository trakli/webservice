<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetPeriodState extends Model
{
    use HasFactory;

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

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }
}
