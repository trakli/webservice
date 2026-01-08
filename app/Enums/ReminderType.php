<?php

namespace App\Enums;

enum ReminderType: string
{
    case DAILY_TRACKING = 'daily_tracking';
    case WEEKLY_REVIEW = 'weekly_review';
    case MONTHLY_SUMMARY = 'monthly_summary';
    case BILL_DUE = 'bill_due';
    case BUDGET_ALERT = 'budget_alert';
    case CUSTOM = 'custom';
}
