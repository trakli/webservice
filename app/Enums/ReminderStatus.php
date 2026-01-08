<?php

namespace App\Enums;

enum ReminderStatus: string
{
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case SNOOZED = 'snoozed';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
