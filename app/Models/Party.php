<?php

namespace App\Models;

use App\Traits\HasClientCreatedAt;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Party',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', description: 'ID of the party'),
        new OA\Property(property: 'name', type: 'string', description: 'Name of the party'),
        new OA\Property(property: 'description', type: 'string', description: 'Description of the party'),
    ]
)]
class Party extends Model
{
    use HasClientCreatedAt, HasFactory, Syncable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'user_id',
    ];

    protected $appends = ['last_synced_at'];

    public function getLastSyncedAtAttribute()
    {
        return $this->syncState?->last_synced_at ?? null;
    }
}
