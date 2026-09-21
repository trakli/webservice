<?php

namespace App\Models;

use App\Enums\StreakPeriod;
use App\Enums\StreakType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Streak',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'owner_id', type: 'integer'),
        new OA\Property(property: 'owner_type', type: 'string', example: 'App\\Models\\User'),
        new OA\Property(property: 'type', type: 'string', enum: ['transaction', 'check_in']),
        new OA\Property(property: 'period', type: 'string', enum: ['daily', 'weekly']),
        new OA\Property(property: 'current_length', type: 'integer'),
        new OA\Property(property: 'longest_length', type: 'integer'),
        new OA\Property(property: 'started_on', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'last_tracked_on', type: 'string', format: 'date', nullable: true),
    ],
    type: 'object'
)]
class Streak extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'owner_type',
        'type',
        'period',
        'current_length',
        'longest_length',
        'last_notified_length',
        'started_on',
        'last_tracked_on',
    ];

    protected $casts = [
        'type' => StreakType::class,
        'period' => StreakPeriod::class,
        'current_length' => 'integer',
        'longest_length' => 'integer',
        'last_notified_length' => 'integer',
        'started_on' => 'date',
        'last_tracked_on' => 'date',
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function isRunning(): bool
    {
        return $this->current_length >= (int) config('streaks.threshold', 3);
    }
}
