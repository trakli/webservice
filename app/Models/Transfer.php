<?php

namespace App\Models;

use App\Traits\HasClientCreatedAt;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Transfer',
    required: ['amount', 'from_wallet_id', 'to_wallet_id', 'user_id'],
    properties: [
        new OA\Property(property: 'id', description: 'ID of the transfer', type: 'integer'),
        new OA\Property(property: 'amount', description: 'Amount of the transaction', type: 'number', format: 'float'),
        new OA\Property(property: 'from_wallet_id', description: 'Source wallet', type: 'integer'),
        new OA\Property(property: 'to_wallet_id', description: 'Destination wallet', type: 'integer'),
        new OA\Property(property: 'exchange_rate', description: 'The exchange rate', type: 'number', format: 'float'),
        new OA\Property(property: 'user_id', description: 'ID of the user', type: 'integer'),
    ],
    type: 'object'
)]
class Transfer extends Model
{
    use HasClientCreatedAt, HasFactory, Syncable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'amount',
        'exchange_rate',
        'user_id',
        'from_wallet_id',
        'to_wallet_id',
    ];

    protected $appends = ['source_wallet', 'destination_wallet', 'last_synced_at', 'client_generated_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'from_wallet_id');
    }

    public function destinationWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'to_wallet_id');
    }

    public function getSourceWalletAttribute()
    {
        return $this->sourceWallet()->first();
    }

    public function getDestinationWalletAttribute()
    {
        return $this->destinationWallet()->first();
    }
}
