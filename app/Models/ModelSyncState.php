<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModelSyncState extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'client_generated_id',
        'last_synced_at',
        'device_id',
    ];

    public function syncable()
    {
        return $this->morphTo();
    }
}
