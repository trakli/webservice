<?php

namespace App\Models;

use App\Traits\Syncable;
use Whilesmart\ModelConfiguration\Models\Configuration as ModelConfiguration;

class Configuration extends ModelConfiguration
{
    use Syncable;

    protected static function booted(): void
    {
        static::addGlobalScope('clientVisible', fn ($query) => $query->where(
            'key',
            '!=',
            \App\Support\CategoryAllowance::KEY,
        ));
    }

    protected $appends = [
        'last_synced_at',
        'client_generated_id',
    ];
}
