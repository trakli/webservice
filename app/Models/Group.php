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
use Whilesmart\Agents\Contracts\HasAgentResource;
use Whilesmart\Agents\Resources\AgentResource;
use Whilesmart\Agents\Resources\ResourceField;

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
class Group extends Model implements HasAgentResource
{
    use HasClientCreatedAt;
    use HasFactory;
    use Iconable;
    use Sluggable;
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
        'slug',
    ];

    protected $appends = ['last_synced_at', 'client_generated_id', 'icon'];

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

    public static function agentResource(): AgentResource
    {
        return new AgentResource(
            name: 'groups',
            model: self::class,
            table: 'groups',
            description: 'Groupings the user files transactions and budgets under, such as a household or a project.',
            aliases: ['group', 'projects', 'households'],
            labelColumn: 'name',
            ownerKey: 'user_id',
            readable: [
                ResourceField::key(),
                ResourceField::string('name', 'Group name'),
                ResourceField::string('slug', 'URL-safe form of the name'),
                ResourceField::text('description', 'What the group covers'),
                ResourceField::internal('user_id'),
                ResourceField::datetime('created_at'),
            ],
            writable: [
                ResourceField::string('name', 'Group name', required: true, rules: 'required|string|max:255'),
                ResourceField::text('description', 'What the group covers', rules: 'nullable|string'),
            ],
            writeEnabled: true,
        );
    }
}
