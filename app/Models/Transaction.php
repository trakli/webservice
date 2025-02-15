<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Transaction',
    required: ['amount', 'type'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', description: 'ID of the transaction'),
        new OA\Property(property: 'type', type: 'string', enum: ['income', 'expense'], description: 'Type of the transaction (income or expense)'),
        new OA\Property(property: 'amount', type: 'number', format: 'float', description: 'Amount of the transaction'),
        new OA\Property(property: 'description', type: 'string', description: 'Description of the transaction'),
        new OA\Property(property: 'datetime', type: 'string', format: 'date', description: 'Date and time of the transaction'),
        new OA\Property(
            property: 'categories',
            description: 'List of categories of the transaction',
            type: 'array',
            items: new OA\Items(type: 'integer', description: 'Category ID array')
        ),
        new OA\Property(property: 'user_id', type: 'integer', description: 'ID of the user who created the transaction'),
        new OA\Property(property: 'transfer_id', type: 'integer', description: 'ID of the associated transfer, if any'),

    ],
    type: 'object'
)]
class Transaction extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'amount',
        'datetime',
        'description',
        'type',
        'party_id',
        'wallet_id',
        'user_id',
        'transfer_id',
    ];

    protected $appends = ['wallet', 'party', 'categories'];

    public function getCategoriesAttribute()
    {
        return $this->categories()->get();
    }

    public function categories(): MorphToMany
    {
        return $this->morphToMany(Category::class, 'categorizable');
    }

    public function getWalletAttribute()
    {
        return $this->wallet()->first();
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function getPartyAttribute()
    {
        return $this->party()->first();
    }

    public function party()
    {
        return $this->belongsTo(Party::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
