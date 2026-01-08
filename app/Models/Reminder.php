<?php

namespace App\Models;

use App\Enums\ReminderStatus;
use App\Enums\ReminderType;
use App\Traits\Syncable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OpenApi\Attributes as OA;
use RRule\RRule;

#[OA\Schema(
    schema: 'Reminder',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'type', type: 'string', enum: ['daily_tracking', 'weekly_review', 'monthly_summary', 'bill_due', 'budget_alert', 'custom']),
        new OA\Property(property: 'trigger_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'due_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'repeat_rule', type: 'string', nullable: true, example: 'FREQ=DAILY;BYHOUR=20;BYMINUTE=0'),
        new OA\Property(property: 'timezone', type: 'string', example: 'UTC'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'paused', 'snoozed', 'completed', 'cancelled']),
        new OA\Property(property: 'priority', type: 'integer'),
        new OA\Property(property: 'snoozed_until', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'last_triggered_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'next_trigger_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'metadata', type: 'object', nullable: true),
    ],
    type: 'object'
)]
class Reminder extends Model
{
    use HasFactory, SoftDeletes, Syncable;

    protected $fillable = [
        'title',
        'description',
        'type',
        'trigger_at',
        'due_at',
        'repeat_rule',
        'timezone',
        'status',
        'priority',
        'snoozed_until',
        'last_triggered_at',
        'next_trigger_at',
        'metadata',
    ];

    protected $casts = [
        'trigger_at' => 'datetime',
        'due_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'last_triggered_at' => 'datetime',
        'next_trigger_at' => 'datetime',
        'metadata' => 'array',
        'status' => ReminderStatus::class,
        'type' => ReminderType::class,
        'priority' => 'integer',
    ];

    protected $appends = ['last_synced_at', 'client_generated_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRecurring(): bool
    {
        return ! empty($this->repeat_rule);
    }

    public function getNextOccurrence(?\DateTimeInterface $after = null): ?\DateTime
    {
        if (! $this->isRecurring()) {
            return $this->trigger_at?->toDateTime();
        }

        try {
            $after = $after ?? now($this->timezone);
            $rrule = new RRule($this->repeat_rule, $this->trigger_at ?? now($this->timezone));

            foreach ($rrule as $occurrence) {
                if ($occurrence > $after) {
                    return $occurrence;
                }
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }

    public function getOccurrences(int $limit = 10, ?\DateTimeInterface $after = null): array
    {
        if (! $this->isRecurring()) {
            return $this->trigger_at ? [$this->trigger_at->toDateTime()] : [];
        }

        try {
            $after = $after ?? now($this->timezone);
            $rrule = new RRule($this->repeat_rule, $this->trigger_at ?? now($this->timezone));
            $occurrences = [];

            foreach ($rrule as $occurrence) {
                if ($occurrence > $after) {
                    $occurrences[] = $occurrence;
                    if (count($occurrences) >= $limit) {
                        break;
                    }
                }
            }

            return $occurrences;
        } catch (\Exception $e) {
            return [];
        }
    }

    public function calculateNextTrigger(): void
    {
        $next = $this->getNextOccurrence();
        $this->next_trigger_at = $next;
    }

    public function snooze(\DateTimeInterface $until): void
    {
        $this->status = ReminderStatus::SNOOZED;
        $this->snoozed_until = $until;
        $this->save();
    }

    public function pause(): void
    {
        $this->status = ReminderStatus::PAUSED;
        $this->save();
    }

    public function resume(): void
    {
        $this->status = ReminderStatus::ACTIVE;
        $this->snoozed_until = null;
        $this->calculateNextTrigger();
        $this->save();
    }

    public function complete(): void
    {
        $this->status = ReminderStatus::COMPLETED;
        $this->save();
    }

    public function cancel(): void
    {
        $this->status = ReminderStatus::CANCELLED;
        $this->save();
    }

    public function scopeActive($query)
    {
        return $query->where('status', ReminderStatus::ACTIVE);
    }

    public function scopeDue($query)
    {
        return $query->active()
            ->whereNotNull('next_trigger_at')
            ->where('next_trigger_at', '<=', now());
    }
}
