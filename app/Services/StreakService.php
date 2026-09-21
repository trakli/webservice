<?php

namespace App\Services;

use App\Enums\StreakPeriod;
use App\Enums\StreakType;
use App\Mail\StreakMilestoneMail;
use App\Models\Streak;
use App\Models\User;
use App\Support\ConfigurationKeys;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;

class StreakService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * Record that the owner did something worth a streak, and return the
     * streaks that grew. One row per period, so a single action can advance
     * both the daily and the weekly count.
     *
     * @return array<Streak>
     */
    public function track(Model $owner, StreakType $type, ?CarbonImmutable $occurredAt = null): array
    {
        $occurredAt ??= CarbonImmutable::now();
        $advanced = [];

        foreach (StreakPeriod::cases() as $period) {
            $streak = $this->advance($owner, $type, $period, $occurredAt);

            if ($streak !== null) {
                $advanced[] = $streak;
                $this->announce($streak);
            }
        }

        return $advanced;
    }

    /**
     * Returns the streak only when this action moved it on. Acting twice in the
     * same bucket is normal, so that case is silent rather than an error.
     */
    private function advance(Model $owner, StreakType $type, StreakPeriod $period, CarbonImmutable $occurredAt): ?Streak
    {
        $bucket = $period->bucket($occurredAt->setTimezone($this->timezoneFor($owner)));

        $streak = Streak::firstOrNew([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'type' => $type,
            'period' => $period,
        ]);

        $last = $streak->last_tracked_on
            ? $period->bucket(CarbonImmutable::parse($streak->last_tracked_on->format('Y-m-d'), $bucket->timezone))
            : null;

        if ($last !== null && $last->greaterThanOrEqualTo($bucket)) {
            return null;
        }

        $continues = $last !== null && $last->equalTo($period->previousBucket($bucket));

        $streak->current_length = $continues ? $streak->current_length + 1 : 1;
        $streak->started_on = $continues ? $streak->started_on : $bucket;
        $streak->longest_length = max((int) $streak->longest_length, $streak->current_length);
        $streak->last_tracked_on = $bucket;

        if (! $continues) {
            $streak->last_notified_length = 0;
        }

        $streak->save();

        return $streak;
    }

    /**
     * Mail only on the lengths worth hearing about, so a long streak does not
     * mean a message every single day.
     */
    private function announce(Streak $streak): void
    {
        if (! config('streaks.mail.enabled', true)) {
            return;
        }

        if (! in_array($streak->type->value, (array) config('streaks.mail.types', []), true)) {
            return;
        }

        $milestone = $this->milestoneFor($streak->current_length);

        if ($milestone === null || $milestone <= $streak->last_notified_length) {
            return;
        }

        $recipient = $streak->owner;

        if (! $recipient instanceof Model || empty($recipient->email)) {
            return;
        }

        if ($recipient instanceof User && ! $this->notifications->isChannelEnabled($recipient, 'email')) {
            return;
        }

        $streak->forceFill(['last_notified_length' => $milestone])->save();

        Mail::to($recipient->email)->queue(new StreakMilestoneMail($streak, $milestone));
    }

    private function milestoneFor(int $length): ?int
    {
        $milestones = array_unique(array_merge(
            [(int) config('streaks.threshold', 3)],
            (array) config('streaks.milestones', [])
        ));

        return in_array($length, $milestones, true) ? $length : null;
    }

    /**
     * A day belongs to whoever lived it. Falls back to the application zone
     * for an owner that carries no preference, such as a group.
     */
    private function timezoneFor(Model $owner): string
    {
        $configured = method_exists($owner, 'getConfigValue')
            ? $owner->getConfigValue(ConfigurationKeys::TIMEZONE)
            : null;

        return is_string($configured) && $configured !== '' ? $configured : config('app.timezone');
    }
}
