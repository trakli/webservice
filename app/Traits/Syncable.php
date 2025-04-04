<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait Syncable
{
    public function scopeUnsynced(Builder $query)
    {
        return $query->whereNull('last_synced_at')
            ->orWhereColumn('updated_at', '>', 'last_synced_at');
    }

    public function markAsSynced()
    {
        $this->updateQuietly(['last_synced_at' => now()]);
    }
}
