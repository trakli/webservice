<?php

namespace App\Models;

use App\Traits\Groupable;
use App\Traits\HasClientCreatedAt;
use App\Traits\Refundable;
use App\Traits\Syncable;
use Whilesmart\Shareables\Traits\Shareable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Whilesmart\Agents\Contracts\HasAgentResource;
use Whilesmart\Agents\Resources\AgentResource;
use Whilesmart\Agents\Resources\ResourceField;
use Whilesmart\Agents\Resources\ResourceRelationship;
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
        new OA\Property(property: 'owed_amount', type: 'number', nullable: true),
        new OA\Property(property: 'debt', type: 'object', properties: [
            new OA\Property(property: 'owed', type: 'number'),
            new OA\Property(property: 'paid', type: 'number'),
            new OA\Property(property: 'remaining', type: 'number'),
            new OA\Property(property: 'currency', type: 'string'),
            new OA\Property(property: 'status', type: 'string', enum: ['open', 'settled']),
        ]),
        new OA\Property(property: 'settles', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'debt_transaction_id', type: 'integer'),
            new OA\Property(property: 'amount_applied', type: 'number'),
            new OA\Property(property: 'currency', type: 'string'),
            new OA\Property(property: 'rate', type: 'number'),
            new OA\Property(property: 'rate_source', type: 'string', enum: ['paid', 'market']),
        ])),
        new OA\Property(property: 'unapplied', type: 'number'),
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
class Transaction extends Model implements HasAgentResource
{
    use Groupable;
    use HasClientCreatedAt;
    use HasFactory;
    use \App\Traits\HasFinanceOwner;
    use Refundable;
    use SoftDeletes;
    use Syncable;
    use Shareable;

    private ?array $requestedSettles = null;

    public function setSettlesAttribute(array $value): void
    {
        $this->requestedSettles = $value;
        $this->attributes['debt_settlements_explicit'] = true;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), app(\App\Services\DebtService::class)->fields($this));
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function (Transaction $transaction) {
            $partyIds = array_filter([$transaction->party_id, $transaction->getOriginal('party_id')]);
            if ($partyIds) {
                Party::whereIn('id', $partyIds)->orderBy('id')->lockForUpdate()->get();
            }
            app(\App\Services\DebtService::class)->validate($transaction);
            app(\App\Services\DebtService::class)->validateExisting($transaction);
            if (empty($transaction->wallet_id)) {
                throw new InvalidArgumentException('A transaction must belong to a wallet.');
            }
            $wallet = Wallet::withTrashed()->findOrFail($transaction->wallet_id);
            if (! $transaction->hasSameOwner($wallet)) {
                throw new InvalidArgumentException('A transaction and its wallet must have the same owner.');
            }
            if ($transaction->party_id && ! $transaction->hasSameOwner(Party::withTrashed()->findOrFail($transaction->party_id))) {
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(422, 'A transaction and its party must have the same owner.');
            }
        });
        static::saved(function (Transaction $transaction) {
            if (! \App\Enums\TransactionIntent::tryFrom($transaction->intent ?? 'regular')?->isLoanOrDebt()
                && ! \App\Enums\TransactionIntent::tryFrom($transaction->getOriginal('intent') ?? 'regular')?->isLoanOrDebt()
                && $transaction->requestedSettles === null) {
                return;
            }
            $transaction->unsetRelation('wallet');
            $service = app(\App\Services\DebtService::class);
            if ($transaction->wasChanged(['party_id', 'intent', 'wallet_id'])) {
                \Illuminate\Support\Facades\DB::table('debt_settlements')->where('is_explicit', false)
                    ->where(fn ($q) => $q->where('payment_transaction_id', $transaction->id)->orWhere('debt_transaction_id', $transaction->id))->delete();
            }
            $old = new Transaction();
            $old->forceFill([
                'owner_type' => $transaction->owner_type, 'owner_id' => $transaction->owner_id,
                'party_id' => $transaction->getOriginal('party_id'),
            ]);
            if ($transaction->wasChanged(['party_id', 'intent', 'wallet_id'])) {
                if ($transaction->debt_settlements_explicit && $transaction->requestedSettles === null) {
                    $links = \Illuminate\Support\Facades\DB::table('debt_settlements')
                        ->where('payment_transaction_id', $transaction->id)->get(['debt_transaction_id', 'amount_applied'])
                        ->map(fn ($item) => (array) $item)->all();
                    if ($links) {
                        $service->explicit($transaction, $links);
                    }
                }
                if ($old->party_id !== $transaction->party_id) {
                    $service->recompute($old);
                }
            }
            if ($transaction->requestedSettles !== null) {
                $service->explicit($transaction, $transaction->requestedSettles);
                $transaction->requestedSettles = null;
            } elseif ($transaction->debt_settlements_explicit && $transaction->wasChanged('amount')) {
                $links = \Illuminate\Support\Facades\DB::table('debt_settlements')->where('payment_transaction_id', $transaction->id)
                    ->get(['debt_transaction_id', 'amount_applied'])->map(fn ($item) => (array) $item)->all();
                $service->explicit($transaction, $links);
            }
            $service->recompute($transaction);
        });
        static::deleted(function (Transaction $transaction) {
            \Illuminate\Support\Facades\DB::table('debt_settlements')
                ->where('debt_transaction_id', $transaction->id)->orWhere('payment_transaction_id', $transaction->id)->delete();
            app(\App\Services\DebtService::class)->recompute($transaction);
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'amount',
        'owed_amount',
        'datetime',
        'description',
        'type',
        'intent',
        'party_id',
        'wallet_id',
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
        // Matches the column scale; 2 would round sub-cent amounts
        // (crypto transfer legs) in every response.
        'amount' => 'decimal:4',
        'owed_amount' => 'decimal:4',
        'debt_settlements_explicit' => 'boolean',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'intent' => 'regular',
    ];

    protected $hidden = ['debt_settlements_explicit', 'transfer', 'groups', 'recurringTransactionRule', 'refund'];

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
            ->ownedBy($user)
            ->join('model_sync_states', 'transactions.id', '=', 'model_sync_states.syncable_id')
            ->where('model_sync_states.syncable_type', static::class)
            ->where('model_sync_states.client_generated_id', $parts[1])
            ->where('model_sync_states.device_id', $device->id)
            ->select('transactions.*')
            ->first();
    }

    public function save(array $options = [])
    {
        return \Illuminate\Support\Facades\DB::transaction(fn () => parent::save($options));
    }

    public function delete()
    {
        return \Illuminate\Support\Facades\DB::transaction(function () {
            $this->recurringTransactionRule()->delete();
            return parent::delete();
        });
    }

    public static function agentResource(): AgentResource
    {
        return new AgentResource(
            name: 'transactions',
            model: self::class,
            table: 'transactions',
            description: 'Money in and out. An income or expense against one wallet.',
            aliases: ['spending', 'expenses', 'income', 'payments', 'purchases'],
            labelColumn: 'description',
            ownerKey: 'owner_id',
            ownerParam: 'user_id',
            ownerConstants: ['owner_type' => User::class],
            ownerBypassRoles: ['admin'],
            readable: [
                ResourceField::key(),
                ResourceField::decimal('owed_amount', 'Amount owed on an opening debt; null means the full amount'),
                ResourceField::decimal('amount', "Amount in the wallet's currency"),
                ResourceField::enum('type', ['income', 'expense'], 'Whether money came in or went out'),
                ResourceField::string(
                    'intent',
                    'What the movement is: regular, loan_given, loan_collected, loan_received, loan_repayment, debt_owed, '
                        . 'debt_settled, investment_buy, investment_return, gift',
                ),
                ResourceField::datetime('datetime', 'When the transaction happened'),
                ResourceField::text('description', 'What it was for'),
                ResourceField::reference('wallet_id', 'wallets.id', 'Wallet the money moved through'),
                ResourceField::reference('party_id', 'parties.id', 'Who it was with'),
                ResourceField::reference(
                    'transfer_id',
                    'transfers.id',
                    'Set when this row is one leg of a wallet-to-wallet transfer rather than real income or spending',
                ),
                ResourceField::internal('owner_id'),
                ResourceField::internal('owner_type', 'string'),
                ResourceField::internal('created_by_user_id'),
            ],
            relationships: [
                ResourceRelationship::belongsTo('transaction_wallet', 'wallets', 'wallet_id', 'Each transaction belongs to a wallet'),
                ResourceRelationship::belongsTo('transaction_party', 'parties', 'party_id', 'Each transaction may have a counterparty'),
                ResourceRelationship::belongsTo('transaction_transfer', 'transfers', 'transfer_id', 'A transfer leg points at its transfer'),
            ],
            orderColumn: 'datetime',
            readTool: false,
        );
    }
}
