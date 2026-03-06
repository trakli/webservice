<?php

namespace App\Models;

use App\Traits\HasClientCreatedAt;
use App\Traits\Iconable;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Party',
    properties: [
        new OA\Property(property: 'id', description: 'ID of the party', type: 'integer'),
        new OA\Property(property: 'type', description: 'Type of the party', type: 'string'),
        new OA\Property(property: 'name', description: 'Name of the party', type: 'string'),
        new OA\Property(property: 'description', description: 'Description of the party', type: 'string'),
        new OA\Property(property: 'icon', description: 'Party icon', properties: [
            new OA\Property(property: 'id', description: 'ID of the icon', type: 'integer'),
            new OA\Property(property: 'path', description: 'Image of the icon', type: 'string'),
            new OA\Property(property: 'type', description: 'type of icon( image or icon or emoji)', type: 'string'),
        ], type: 'object'),
    ],
    type: 'object'
)]
class Party extends Model
{
    use HasClientCreatedAt;
    use HasFactory;
    use Iconable;
    use SoftDeletes;
    use Syncable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'user_id',
        'type',
    ];

    protected $appends = ['last_synced_at', 'client_generated_id', 'icon'];
}
