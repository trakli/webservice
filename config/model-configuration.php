<?php

use App\Hooks\ModelConfigurationFilterHook;

return [
    'register_routes' => true,
    'route_prefix' => env('MODEL_CONFIG_ROUTE_PREFIX', 'api/v1'),
    'auth_middleware' => ['auth:sanctum'],
    'allow_case_insensitive_keys' => false,
    'allowed_keys' => [
        'default-wallet',
        'default-currency',
        'default-group',
        'default-lang',
        'onboarding-complete',
        'theme',
        'manual-exchange-rates',
        'notifications-email',
        'notifications-push',
        'notifications-inapp',
        'notifications-reminders',
        'notifications-insights',
        'notifications-inactivity',
    ],
    'model' => \App\Models\Configuration::class,
    'hooks' => [ModelConfigurationFilterHook::class],
];
