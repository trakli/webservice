<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Wallet',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', description: 'ID of the wallet'),
        new OA\Property(property: 'name', type: 'string', description: 'Name of the wallet'),
        new OA\Property(property: 'description', type: 'string', description: 'Description of the wallet'),
    ]
)]
class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'user_id',
    ];
}
