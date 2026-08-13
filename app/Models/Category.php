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
    schema: 'Category',
    properties: [
        new OA\Property(property: 'id', description: 'ID of the category', type: 'integer'),
        new OA\Property(property: 'name', description: 'Name of the category', type: 'string'),
        new OA\Property(property: 'description', description: 'Description of the category', type: 'string'),
        new OA\Property(property: 'type', description: 'Type of the category (income or expense)', type: 'string'),
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
class Category extends Model implements HasAgentResource
{
    use HasClientCreatedAt;
    use HasFactory;
    use Iconable;
    use Sluggable;
    use SoftDeletes;
    use Syncable;

    public const TYPE_INCOME = 'income';

    public const TYPE_EXPENSE = 'expense';

    public const TYPES = [
        self::TYPE_INCOME,
        self::TYPE_EXPENSE,
    ];

    protected $fillable = [
        'name',
        'description',
        'type',
        'user_id',
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

    public static function agentResource(): AgentResource
    {
        return new AgentResource(
            name: 'categories',
            model: self::class,
            table: 'categories',
            description: 'Labels the user classifies transactions under.',
            aliases: ['category', 'tags', 'labels'],
            labelColumn: 'name',
            ownerKey: 'user_id',
            ownerBypassRoles: ['admin'],
            readable: [
                ResourceField::key(),
                ResourceField::string('name', 'Category name'),
                ResourceField::enum('type', ['income', 'expense', 'invoice'], 'What the category applies to'),
                ResourceField::text('description', 'What the category covers'),
                ResourceField::internal('user_id'),
            ],
            readTool: false,
        );
    }
}
