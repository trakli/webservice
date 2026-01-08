<?php

namespace App\Enums;

enum NotificationType: string
{
    case REMINDER = 'reminder';
    case ALERT = 'alert';
    case ACHIEVEMENT = 'achievement';
    case SYSTEM = 'system';
}
