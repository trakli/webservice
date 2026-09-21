<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum StreakPeriod: string
{
    case DAILY = 'daily';
    case WEEKLY = 'weekly';

    /**
     * The first day of the bucket the given date falls in, so two dates in the
     * same bucket compare equal and adjacent buckets are exactly one step apart.
     */
    public function bucket(CarbonImmutable $date): CarbonImmutable
    {
        return match ($this) {
            self::DAILY => $date->startOfDay(),
            self::WEEKLY => $date->startOfWeek(),
        };
    }

    public function previousBucket(CarbonImmutable $bucket): CarbonImmutable
    {
        return match ($this) {
            self::DAILY => $bucket->subDay(),
            self::WEEKLY => $bucket->subWeek(),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DAILY => __('day'),
            self::WEEKLY => __('week'),
        };
    }
}
