<?php

namespace App\Traits;

use App\Models\ModelSyncState;
use Illuminate\Support\Facades\Log;

trait Syncable
{
    public static function bootSyncable(): void
    {
        static::created(function ($model) {
            $model->syncState()->create([
                'last_synced_at' => now(),
            ]);
        });
    }

    public function setClientGeneratedId(string $value)
    {
        if (empty($value)) {
            Log::info('Client generated ID is empty, skipping updateOrCreate for client_generated_id.');

            return;
        }
        $this->syncState()->updateOrCreate([], ['client_generated_id' => $value]);
    }

    public function getLastSyncedAtAttribute(): ?string
    {
        return $this->syncState?->last_synced_at ?? null;
    }

    public function getClientGeneratedIdAttribute(): ?string
    {
        return $this->syncState?->client_generated_id ?? null;
    }

    public function syncState()
    {
        return $this->morphOne(ModelSyncState::class, 'syncable');
    }

    public function markAsSynced()
    {
        return $this->syncState()->updateOrCreate([], ['last_synced_at' => now()]);
    }
}
