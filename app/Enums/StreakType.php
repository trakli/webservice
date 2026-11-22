<?php

namespace App\Enums;

enum StreakType: string
{
    case TRANSACTION = 'transaction';
    case CHECK_IN = 'check_in';

    public function label(): string
    {
        return match ($this) {
            self::TRANSACTION => __('tracking'),
            self::CHECK_IN => __('checking in'),
        };
    }
}
