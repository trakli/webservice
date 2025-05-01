<?php

namespace App\Models;

use App\Traits\HasClientCreatedAt;
use App\Traits\Syncable;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Category',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', description: 'ID of the category'),
        new OA\Property(property: 'name', type: 'string', description: 'Name of the category'),
        new OA\Property(property: 'description', type: 'string', description: 'Description of the category'),
        new OA\Property(property: 'type', type: 'string', description: 'Type of the category (income or expense)'),
    ]
)]
class Category extends Model
{
    use HasClientCreatedAt, HasFactory, Sluggable, Syncable;

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

    protected $appends = ['last_synced_at', 'client_generated_id'];

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
}
