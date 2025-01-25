<?php

namespace App\Models;

use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Group',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', description: 'ID of the group'),
        new OA\Property(property: 'name', type: 'string', description: 'Name of the group'),
        new OA\Property(property: 'description', type: 'string', description: 'Description of the group'),
    ]
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

    use HasFactory, Sluggable;

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
