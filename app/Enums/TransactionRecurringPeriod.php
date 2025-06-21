<?php

namespace App\Enums;

enum TransactionRecurringPeriod: string
{
    case DAILY = 'daily';
    case WEEKLY = 'weekly';

    case MONTHLY = 'monthly';

    case YEARLY = 'yearly';
}
