<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Wallet',
    properties: [
        new OA\Property(property: 'id', description: 'ID of the wallet', type: 'integer'),
        new OA\Property(property: 'name', description: 'Name of the wallet', type: 'string'),
        new OA\Property(property: 'description', description: 'Description of the wallet', type: 'string'),
        new OA\Property(property: 'currency', description: 'Currency of the wallet', type: 'string'),
        new OA\Property(property: 'balance', description: 'Balance of the wallet', type: 'number', format: 'float'),
    ],
    type: 'object'
)]
class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'user_id',
        'currency',
        'balance',
    ];
}
