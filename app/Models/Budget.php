<?php

namespace App\Models;

use App\Contracts\OwnerResolver;
use App\Services\BudgetProgressService;
use App\Traits\HasClientCreatedAt;
use App\Traits\Remindable;
use App\Traits\Syncable;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Budget',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'owner_id', type: 'integer'),
        new OA\Property(property: 'owner_type', type: 'string', example: 'App\\Models\\User'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'slug', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'amount', type: 'number', format: 'float'),
        new OA\Property(property: 'currency', type: 'string', example: 'USD'),
        new OA\Property(property: 'period_type', type: 'string', enum: ['weekly', 'monthly', 'yearly', 'custom']),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'rollover_enabled', type: 'boolean'),
        new OA\Property(property: 'threshold_percent', type: 'integer', example: 80),
        new OA\Property(property: 'forecast_alerts_enabled', type: 'boolean'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(
            property: 'targets',
            type: 'array',
            items: new OA\Items(properties: [
                new OA\Property(property: 'type', type: 'string', enum: ['category', 'group', 'wallet']),
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'client_generated_id', type: 'string', nullable: true),
                new OA\Property(property: 'name', type: 'string'),
            ], type: 'object')
        ),
        new OA\Property(property: 'progress', ref: '#/components/schemas/BudgetProgress'),
        new OA\Property(property: 'client_generated_id', type: 'string', nullable: true),
        new OA\Property(property: 'last_synced_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'BudgetProgress',
    properties: [
        new OA\Property(property: 'period_start', type: 'string', format: 'date'),
        new OA\Property(property: 'period_end', type: 'string', format: 'date'),
        new OA\Property(property: 'limit', type: 'number', format: 'float'),
        new OA\Property(property: 'gross_spent', type: 'number', format: 'float'),
        new OA\Property(property: 'refunds', type: 'number', format: 'float'),
        new OA\Property(property: 'net_spent', type: 'number', format: 'float'),
        new OA\Property(property: 'rollover_in', type: 'number', format: 'float'),
        new OA\Property(property: 'effective_limit', type: 'number', format: 'float'),
        new OA\Property(property: 'remaining', type: 'number', format: 'float'),
        new OA\Property(property: 'percent_used', type: 'integer'),
        new OA\Property(property: 'projected_spend', type: 'number', format: 'float'),
        new OA\Property(property: 'status', type: 'string', enum: ['on_track', 'near_limit', 'over_budget', 'forecast_breach']),
    ],
    type: 'object'
)]
class Budget extends Model
{
    use HasClientCreatedAt;
    use HasFactory;
    use Remindable;
    use Sluggable;
    use SoftDeletes;
    use Syncable;

    public const PERIOD_WEEKLY = 'weekly';

    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_YEARLY = 'yearly';

    public const PERIOD_CUSTOM = 'custom';

    public const PERIODS = [
        self::PERIOD_WEEKLY,
        self::PERIOD_MONTHLY,
        self::PERIOD_YEARLY,
        self::PERIOD_CUSTOM,
    ];

    protected $fillable = [
        'owner_id',
        'owner_type',
        'name',
        'slug',
        'description',
        'amount',
        'currency',
        'period_type',
        'start_date',
        'end_date',
        'rollover_enabled',
        'threshold_percent',
        'forecast_alerts_enabled',
        'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'start_date' => 'date',
        'end_date' => 'date',
        'rollover_enabled' => 'boolean',
        'threshold_percent' => 'integer',
        'forecast_alerts_enabled' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'last_synced_at',
        'client_generated_id',
        'targets',
        'progress',
    ];

    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'name',
            ],
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function categories(): MorphToMany
    {
        return $this->morphedByMany(Category::class, 'budgetable');
    }

    public function groups(): MorphToMany
    {
        return $this->morphedByMany(Group::class, 'budgetable');
    }

    public function wallets(): MorphToMany
    {
        return $this->morphedByMany(Wallet::class, 'budgetable');
    }

    public function periodStates(): HasMany
    {
        return $this->hasMany(BudgetPeriodState::class);
    }

    public function getTargetsAttribute(): array
    {
        $targets = [];

        foreach ($this->categories()->get() as $category) {
            $targets[] = [
                'type' => 'category',
                'id' => $category->id,
                'client_generated_id' => $category->client_generated_id,
                'name' => $category->name,
            ];
        }

        foreach ($this->groups()->get() as $group) {
            $targets[] = [
                'type' => 'group',
                'id' => $group->id,
                'client_generated_id' => $group->client_generated_id,
                'name' => $group->name,
            ];
        }

        foreach ($this->wallets()->get() as $wallet) {
            $targets[] = [
                'type' => 'wallet',
                'id' => $wallet->id,
                'client_generated_id' => $wallet->client_generated_id,
                'name' => $wallet->name,
            ];
        }

        return $targets;
    }

    public function getProgressAttribute(): array
    {
        /** @var BudgetProgressService $service */
        $service = app(BudgetProgressService::class);

        return $service->compute($this);
    }

    /**
     * Budgets visible to the given user across all supported owner types.
     * Today resolves to owner_type=User + owner_id=$user->id; when Workspace
     * / Couple owners land, the OwnerResolver surfaces those too without
     * controller code changing.
     */
    public function scopeVisibleTo(Builder $query, Model $user): Builder
    {
        /** @var OwnerResolver $resolver */
        $resolver = app(OwnerResolver::class);

        $visible = $resolver->visibleOwners($user);

        if (empty($visible)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($visible) {
            foreach ($visible as $entry) {
                $outer->orWhere(function (Builder $inner) use ($entry) {
                    $inner->where('owner_type', $entry['owner_type'])
                        ->whereIn('owner_id', $entry['owner_ids']);
                });
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
