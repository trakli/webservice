<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Whilesmart\UserDevices\Models\Device;

class ModelSyncState extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'client_generated_id',
        'last_synced_at',
        'device_id',
    ];

    public function syncable(): MorphTo
    {
        return $this->morphTo();
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
