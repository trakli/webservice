<?php

namespace App\Models;

use App\Casts\FloatCast;
use App\Traits\HasClientCreatedAt;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Wallet',
    properties: [
        new OA\Property(property: 'id', description: 'ID of the wallet', type: 'integer'),
        new OA\Property(property: 'name', description: 'Name of the wallet', type: 'string'),
        new OA\Property(property: 'type', description: 'Type of the wallet (bank, cash, credit_card, mobile)', type: 'string'),
        new OA\Property(property: 'description', description: 'Description of the wallet', type: 'string'),
        new OA\Property(property: 'currency', description: 'Currency of the wallet', type: 'string'),
        new OA\Property(property: 'balance', description: 'Balance of the wallet', type: 'number', format: 'float'),
    ],
    type: 'object'
)]
class Wallet extends Model
{
    use HasClientCreatedAt, HasFactory, Syncable;

    protected $fillable = [
        'name',
        'type',
        'description',
        'user_id',
        'currency',
        'balance',
    ];

    protected $appends = ['last_synced_at', 'client_generated_id'];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'balance' => FloatCast::class,
    ];
}
