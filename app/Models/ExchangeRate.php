<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $fillable = [
        'base_currency',
        'target_currency',
        'rate',
        'fetched_at',
    ];

    protected $casts = [
        'rate' => 'decimal:8',
        'fetched_at' => 'datetime',
    ];
}
