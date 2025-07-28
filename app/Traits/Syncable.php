<?php

namespace App\Traits;

use App\Models\ModelSyncState;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
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

    public function syncState()
    {
        return $this->morphOne(ModelSyncState::class, 'syncable');
    }

    public function setClientGeneratedId(string $value, Model|Authenticatable $user): void
    {
        if (empty($value)) {
            Log::info('Client generated ID is empty, skipping updateOrCreate for client_generated_id.');

            return;
        }

        $exploded_value = explode(':', $value);
        if (count($exploded_value) !== 2) {
            Log::info('Client ID is not in the format  xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx:xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx.');

            return;
        }

        // assuming the ValidateClientId rule as already checked the validity of the uuid
        $device_id = $exploded_value[0];
        $random_id = $exploded_value[1];

        // check if this device exists
        $device = $user->devices()->where('token', $device_id)->first();
        if (empty($device)) {
            $device = $user->devices()->create(['token' => $device_id]);
        }
        $this->syncState()->updateOrCreate([], ['client_generated_id' => $random_id, 'device_id' => $device->id]);
    }

    public function getLastSyncedAtAttribute(): ?string
    {
        return $this->syncState?->last_synced_at ?? null;
    }

    public function getClientGeneratedIdAttribute(): ?string
    {
        $deviceToken = $this->syncState?->device?->token;
        $clientId = $this->syncState?->client_generated_id;
        if ($deviceToken && $clientId) {
            return $deviceToken.':'.$clientId;
        }

        return null;
    }

    public function markAsSynced()
    {
        return $this->syncState()->updateOrCreate([], ['last_synced_at' => now()]);
    }

    private function splitClientId(string $client_id): array {}
}
