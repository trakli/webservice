<?php

namespace App\Traits;

use App\Models\ModelSyncState;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Whilesmart\UserDevices\Models\Device;

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

    public function setClientGeneratedId(string $value, Model|Authenticatable $user, ?string $deviceToken = null): void
    {
        if (empty($value)) {
            Log::info('Client generated ID is empty, skipping updateOrCreate for client_generated_id.');

            return;
        }

        if ($deviceToken !== null) {
            $device_id = $deviceToken;
            $random_id = $value;
        } else {
            $exploded_value = explode(':', $value);
            if (count($exploded_value) !== 2) {
                Log::info('Client ID is not in the format  xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx:xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx.');

                return;
            }
            $device_id = $exploded_value[0];
            $random_id = $exploded_value[1];
        }

        $device = Device::where('token', $device_id)->first();
        if (empty($device)) {
            $device = $user->devices()->create(['token' => $device_id]);
        } else {
            if ($device->deviceable_id != $user->id) {
                $device->deviceable_id = $user->id;
                $device->save();
            }
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
            return $deviceToken . ':' . $clientId;
        }

        return null;
    }

    public function markAsSynced()
    {
        return $this->syncState()->updateOrCreate([], ['last_synced_at' => now()]);
    }
}
