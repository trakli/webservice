<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Whilesmart\Agents\Contracts\HasAgentResource;
use Whilesmart\Agents\Resources\AgentResource;
use Whilesmart\Agents\Resources\ResourceField;

class ExchangeRate extends Model implements HasAgentResource
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

    public static function agentResource(): AgentResource
    {
        return new AgentResource(
            name: 'exchange_rates',
            model: self::class,
            table: 'exchange_rates',
            description: 'Reference conversion rates between currencies. The same for every user.',
            aliases: ['rates', 'fx', 'currency rates'],
            ownerKey: null,
            global: true,
            readable: [
                ResourceField::key(),
                ResourceField::string('base_currency', 'Currency being converted from'),
                ResourceField::string('target_currency', 'Currency being converted to'),
                ResourceField::decimal('rate', 'Target units per one base unit'),
                ResourceField::datetime('fetched_at', 'When the rate was last refreshed'),
            ],
            orderColumn: 'fetched_at',
        );
    }
}
