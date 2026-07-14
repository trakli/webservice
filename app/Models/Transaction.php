<?php

namespace App\Models;

use App\Traits\Groupable;
use App\Traits\HasClientCreatedAt;
use App\Traits\Refundable;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Whilesmart\UserDevices\Models\Device;

#[OA\Schema(
    schema: 'Transaction',
    required: ['amount', 'type'],
    properties: [
        new OA\Property(property: 'id', description: 'ID of the transaction', type: 'integer'),
        new OA\Property(
            property: 'type',
            description: 'Type of the transaction (income or expense)',
            type: 'string',
            enum: ['income', 'expense']
        ),
        new OA\Property(property: 'amount', description: 'Amount of the transaction', type: 'number', format: 'float'),
        new OA\Property(property: 'description', description: 'Description of the transaction', type: 'string'),
        new OA\Property(
            property: 'datetime',
            description: 'Date and time of the transaction',
            type: 'string',
            format: 'date'
        ),
        new OA\Property(
            property: 'categories',
            description: 'List of categories of the transaction',
            type: 'array',
            items: new OA\Items(description: 'Category ID array', type: 'integer')
        ),
        new OA\Property(
            property: 'is_recurring',
            description: 'Set the transaction as a recurring transaction',
            type: 'boolean'
        ),
        new OA\Property(
            property: 'user_id',
            description: 'ID of the user who created the transaction',
            type: 'integer'
        ),
        new OA\Property(
            property: 'amount_in_budget_currency',
            description: 'Transaction amount converted to the currency of the budget',
            type: 'string'
        ),
        new OA\Property(property: 'transfer_id', description: 'ID of the associated transfer, if any', type: 'integer'),
        new OA\Property(
            property: 'wallet_client_generated_id',
            description: 'Client-generated ID of the associated wallet',
            type: 'string',
            format: 'uuid'
        ),
        new OA\Property(
            property: 'party_client_generated_id',
            description: 'Client-generated ID of the associated party',
            type: 'string',
            format: 'uuid'
        ),
        new OA\Property(
            property: 'files',
            description: 'Files attached to the transaction',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/File')
        ), new OA\Property(
            property: 'recurring_rules',
            ref: '#/components/schemas/RecurringTransactionRule',
            description: 'Recurring rules attached to the transaction'
        ),
    ],
    type: 'object'
)]
class Transaction extends Model
{
    use Groupable;
    use HasClientCreatedAt;
    use HasFactory;
    use Refundable;
    use SoftDeletes;
    use Syncable;

    protected static function boot()
    {
        parent::boot();

        static::saving(function (Transaction $transaction) {
            if (empty($transaction->wallet_id)) {
                throw new InvalidArgumentException('A transaction must belong to a wallet.');
            }
        });
    }

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
        'intent',
        'party_id',
        'wallet_id',
        'user_id',
        'transfer_id',
        'metadata',
        'updated_at',
        'created_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'datetime' => 'datetime',
        'amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'intent' => 'regular',
    ];

    protected $hidden = ['transfer', 'groups', 'recurringTransactionRule', 'refund'];

    protected $appends = [
        'wallet',
        'party',
        'group',
        'categories',
        'last_synced_at',
        'client_generated_id',
        'wallet_client_generated_id',
        'party_client_generated_id',
        'transfer_client_generated_id',
        'files',
        'recurring_rules',
        'is_refund',
        'refund_of_transaction_id',
    ];

    public function getWalletClientGeneratedIdAttribute()
    {
        return $this->wallet ? $this->wallet->client_generated_id : null;
    }

    public function getPartyClientGeneratedIdAttribute()
    {
        return $this->party ? $this->party->client_generated_id : null;
    }

    public function getTransferClientGeneratedIdAttribute()
    {
        if (! $this->transfer_id) {
            return null;
        }

        return $this->transfer?->client_generated_id;
    }

    public function getCategoriesAttribute()
    {
        return $this->getRelationValue('categories');
    }

    public function categories(): MorphToMany
    {
        return $this->morphToMany(Category::class, 'categorizable');
    }

    public function getWalletAttribute()
    {
        return $this->getRelationValue('wallet');
    }

    public function transfer()
    {
        return $this->belongsTo(Transfer::class);
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function getPartyAttribute()
    {
        return $this->getRelationValue('party');
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
        return $this->getRelationValue('files');
    }

    /**
     * Get all files for this transaction.
     */
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    public function getRecurringRulesAttribute()
    {
        return $this->getRelationValue('recurringTransactionRule');
    }

    public function recurringTransactionRule(): HasOne
    {
        return $this->hasOne(RecurringTransactionRule::class);
    }

    public function getIsRefundAttribute(): bool
    {
        return $this->getRelationValue('refund') !== null;
    }

    public function getRefundOfTransactionIdAttribute(): ?int
    {
        return $this->refund?->original_transaction_id;
    }

    /**
     * Scope to exclude transfer transactions.
     */
    public function scopeNonTransfer($query)
    {
        return $query->whereNull($this->getTable() . '.transfer_id');
    }

    public static function findByClientId(string $clientId, User $user): ?self
    {
        $parts = explode(':', $clientId);
        if (count($parts) !== 2) {
            return null;
        }

        $device = Device::where('token', $parts[0])->first();
        if (! $device) {
            return null;
        }

        return static::query()
            ->where('user_id', $user->id)
            ->join('model_sync_states', 'transactions.id', '=', 'model_sync_states.syncable_id')
            ->where('model_sync_states.syncable_type', static::class)
            ->where('model_sync_states.client_generated_id', $parts[1])
            ->where('model_sync_states.device_id', $device->id)
            ->select('transactions.*')
            ->first();
    }

    public function delete()
    {
        $this->recurringTransactionRule()->delete();

        return parent::delete();
    }
}
