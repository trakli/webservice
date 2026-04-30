<?php

namespace App\Traits;

use App\Models\Reminder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Any model that can be the subject of a reminder (a budget, a bill,
 * a sinking fund, a subscription) uses this trait to expose its
 * `reminders()` morphMany relationship and helpers for idempotent
 * reminder creation keyed by a `source` string.
 */
trait Remindable
{
    public function reminders(): MorphMany
    {
        return $this->morphMany(Reminder::class, 'remindable');
    }

    /**
     * Create a reminder for this subject unless one with the same source
     * already exists within the given period window (default: today).
     *
     * Returns the created or existing reminder.
     */
    public function reminderForPeriod(string $source, \DateTimeInterface $periodStart, callable $factory): ?Reminder
    {
        $existing = $this->reminders()
            ->where('source', $source)
            ->where('created_at', '>=', $periodStart)
            ->first();

        if ($existing) {
            return $existing;
        }

        $attributes = $factory();
        $attributes['source'] = $source;

        return $this->reminders()->create($attributes);
    }
}
