<?php

namespace App\Models;

use App\Traits\HasClientCreatedAt;
use App\Traits\Iconable;
use App\Traits\Syncable;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Group',
    properties: [
        new OA\Property(property: 'id', description: 'ID of the group', type: 'integer'),
        new OA\Property(property: 'name', description: 'Name of the group', type: 'string'),
        new OA\Property(property: 'description', description: 'Description of the group', type: 'string'),
        new OA\Property(property: 'icon', description: 'Category icon', properties: [
            new OA\Property(property: 'id', description: 'ID of the icon', type: 'integer'),
            new OA\Property(property: 'path', description: 'Image of the icon', type: 'string'),
            new OA\Property(property: 'type', description: 'type of icon( image or icon or emoji)', type: 'string'),
        ], type: 'object'),
        new OA\Property(property: 'sync_state', description: 'Sync state', properties: [
            new OA\Property(property: 'id', description: 'ID of the sync state', type: 'integer'),
            new OA\Property(property: 'syncable_id', description: 'ID of the syncable', type: 'integer'),
            new OA\Property(property: 'client_generated_id', description: 'ID from the client', type: 'integer'),
            new OA\Property(property: 'syncable_type', description: 'Syncable type', type: 'string'),
            new OA\Property(property: 'source', description: '', type: 'string'),
            new OA\Property(property: 'last_synced_at', description: 'Date last synced', type: 'datetime'),
            new OA\Property(property: 'created_at', description: 'Date created', type: 'datetime'),
            new OA\Property(property: 'deleted_at', description: 'Date deleted', type: 'datetime'),
        ], type: 'object'),
    ],
    type: 'object'
)]
class Group extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'slug',
    ];

    protected $appends = ['last_synced_at', 'client_generated_id', 'icon'];

    use HasClientCreatedAt, HasFactory, Iconable, Sluggable, SoftDeletes, Syncable;

    /**
     * Return the sluggable configuration array for this model.
     */
    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'name',
            ],
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
