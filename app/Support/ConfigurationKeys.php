<?php

namespace App\Support;

class ConfigurationKeys
{
    public const DEFAULT_WALLET = 'default-wallet';
    public const DEFAULT_CURRENCY = 'default-currency';
    public const DEFAULT_GROUP = 'default-group';
    public const DEFAULT_LANG = 'default-lang';
    public const ONBOARDING_COMPLETE = 'onboarding-complete';
    public const THEME = 'theme';
    public const TIMEZONE = 'timezone';
    public const MANUAL_EXCHANGE_RATES = 'manual-exchange-rates';
    public const NOTIFICATIONS_EMAIL = 'notifications-email';
    public const NOTIFICATIONS_PUSH = 'notifications-push';
    public const NOTIFICATIONS_INAPP = 'notifications-inapp';
    public const NOTIFICATIONS_REMINDERS = 'notifications-reminders';
    public const NOTIFICATIONS_INSIGHTS = 'notifications-insights';
    public const NOTIFICATIONS_INACTIVITY = 'notifications-inactivity';
    public const INSIGHTS_FREQUENCY = 'insights-frequency';
    public const INACTIVITY_REMINDERS_ENABLED = 'inactivity-reminders-enabled';
    public const INACTIVITY_REMINDER_COUNT = 'inactivity-reminder-count';
    public const LAST_INACTIVITY_REMINDER_SENT = 'last-inactivity-reminder-sent';
    public const WALLETS_ALLOW_NEGATIVE_BALANCE = 'wallets-allow-negative-balance';

    public const TRANSACTION_INTENTS_ENABLED = 'transaction-intents-enabled';

    public const ASSET_TRACKING_ENABLED = 'asset-tracking-enabled';

    public const LANDING_EXPERIENCE = 'landing-experience';

    public const NAMES = [
        self::DEFAULT_WALLET,
        self::DEFAULT_CURRENCY,
        self::DEFAULT_GROUP,
        self::DEFAULT_LANG,
        self::ONBOARDING_COMPLETE,
        self::THEME,
        self::TIMEZONE,
        self::MANUAL_EXCHANGE_RATES,
        self::NOTIFICATIONS_EMAIL,
        self::NOTIFICATIONS_PUSH,
        self::NOTIFICATIONS_INAPP,
        self::NOTIFICATIONS_REMINDERS,
        self::NOTIFICATIONS_INSIGHTS,
        self::NOTIFICATIONS_INACTIVITY,
        self::INSIGHTS_FREQUENCY,
        self::INACTIVITY_REMINDERS_ENABLED,
        self::INACTIVITY_REMINDER_COUNT,
        self::LAST_INACTIVITY_REMINDER_SENT,
        self::WALLETS_ALLOW_NEGATIVE_BALANCE,
        self::TRANSACTION_INTENTS_ENABLED,
        self::ASSET_TRACKING_ENABLED,
        self::LANDING_EXPERIENCE,
    ];

    public const RULES = [
        self::DEFAULT_WALLET => 'string',
        self::DEFAULT_CURRENCY => 'string|max:10',
        self::DEFAULT_GROUP => 'string',
        self::DEFAULT_LANG => 'string|max:10',
        self::ONBOARDING_COMPLETE => 'boolean',
        self::THEME => 'string|in:light,dark,system',
        self::TIMEZONE => 'timezone',
        self::MANUAL_EXCHANGE_RATES => 'json',
        self::NOTIFICATIONS_EMAIL => 'boolean',
        self::NOTIFICATIONS_PUSH => 'boolean',
        self::NOTIFICATIONS_INAPP => 'boolean',
        self::NOTIFICATIONS_REMINDERS => 'boolean',
        self::NOTIFICATIONS_INSIGHTS => 'boolean',
        self::NOTIFICATIONS_INACTIVITY => 'boolean',
        self::INSIGHTS_FREQUENCY => 'string|in:daily,weekly,monthly',
        self::INACTIVITY_REMINDERS_ENABLED => 'boolean',
        self::INACTIVITY_REMINDER_COUNT => 'integer|min:0',
        self::LAST_INACTIVITY_REMINDER_SENT => 'date',
        self::WALLETS_ALLOW_NEGATIVE_BALANCE => 'boolean',
        self::TRANSACTION_INTENTS_ENABLED => 'boolean',
        self::ASSET_TRACKING_ENABLED => 'boolean',
        self::LANDING_EXPERIENCE => 'string|in:chat,dashboard',
    ];
}
