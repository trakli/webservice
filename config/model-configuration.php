<?php

use App\Hooks\ModelConfigurationFilterHook;

return [
    'register_routes' => true,
    'route_prefix' => env('MODEL_CONFIG_ROUTE_PREFIX', 'api/v1'),
    'auth_middleware' => ['auth:sanctum'],
    'allow_case_insensitive_keys' => false,
    'allowed_keys' => [
        'default-wallet' => 'string',
        'default-currency' => 'string|max:10',
        'default-group' => 'string',
        'default-lang' => 'string|max:10',
        'onboarding-complete' => 'boolean',
        'theme' => 'string|in:light,dark,system',
        'timezone' => 'timezone',
        'manual-exchange-rates' => 'json',
        'notifications-email' => 'boolean',
        'notifications-push' => 'boolean',
        'notifications-inapp' => 'boolean',
        'notifications-reminders' => 'boolean',
        'notifications-insights' => 'boolean',
        'notifications-inactivity' => 'boolean',
        'insights-frequency' => 'string|in:daily,weekly,monthly',
    ],
    'model' => \App\Models\Configuration::class,
    'hooks' => [ModelConfigurationFilterHook::class],
];
