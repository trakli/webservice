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
        new OA\Property(
            property: 'stats',
            type: 'object',
            properties: [
                new OA\Property(property: 'total_income', type: 'number', format: 'float'),
                new OA\Property(property: 'total_expense', type: 'number', format: 'float'),
            ]
        ),
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

    protected $appends = ['last_synced_at', 'client_generated_id', 'stats'];

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function getStatsAttribute()
    {
        $transactions = $this->transactions()
            ->selectRaw('
                COALESCE(SUM(CASE WHEN type = "income" THEN amount ELSE 0 END), 0) as total_income,
                COALESCE(SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END), 0) as total_expense
            ')
            ->first();

        return [
            'total_income' => (float) $transactions->total_income,
            'total_expense' => (float) $transactions->total_expense,
        ];
    }

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'balance' => FloatCast::class,
    ];
}
