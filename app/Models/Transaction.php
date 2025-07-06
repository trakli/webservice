<?php

namespace App\Models;

use App\Traits\HasClientCreatedAt;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Transaction',
    required: ['amount', 'type'],
    properties: [
        new OA\Property(property: 'id', description: 'ID of the transaction', type: 'integer'),
        new OA\Property(property: 'type', description: 'Type of the transaction (income or expense)', type: 'string', enum: ['income', 'expense']),
        new OA\Property(property: 'amount', description: 'Amount of the transaction', type: 'number', format: 'float'),
        new OA\Property(property: 'description', description: 'Description of the transaction', type: 'string'),
        new OA\Property(property: 'datetime', description: 'Date and time of the transaction', type: 'string', format: 'date'),
        new OA\Property(
            property: 'categories',
            description: 'List of categories of the transaction',
            type: 'array',
            items: new OA\Items(description: 'Category ID array', type: 'integer')
        ),
        new OA\Property(property: 'user_id', description: 'ID of the user who created the transaction', type: 'integer'),
        new OA\Property(property: 'transfer_id', description: 'ID of the associated transfer, if any', type: 'integer'),
        new OA\Property(property: 'wallet_client_generated_id', description: 'Client-generated ID of the associated wallet', type: 'string', format: 'uuid'),
        new OA\Property(property: 'party_client_generated_id', description: 'Client-generated ID of the associated party', type: 'string', format: 'uuid'),
        new OA\Property(
            property: 'files',
            description: 'Files attached to the transaction',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/File')
        ),
        new OA\Property(property: 'last_synced_at', description: 'The time when the client last synced with the server', type: 'datetime'),
    ],
    type: 'object'
)]
class Transaction extends Model
{
    use HasClientCreatedAt, HasFactory, Syncable;

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
        'updated_at',
        'created_at',
    ];

    protected $appends = [
        'wallet',
        'party',
        'categories',
        'last_synced_at',
        'client_generated_id',
        'wallet_client_generated_id',
        'party_client_generated_id',
        'files',
    ];

    public function getWalletClientGeneratedIdAttribute()
    {
        return $this->wallet ? $this->wallet->client_generated_id : null;
    }

    public function getPartyClientGeneratedIdAttribute()
    {
        return $this->party ? $this->party->client_generated_id : null;
    }

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

    public function getFilesAttribute()
    {
        return $this->files()->get();
    }

    /**
     * Get all files for this transaction.
     */
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }
}
