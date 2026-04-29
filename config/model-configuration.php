<?php

use App\Hooks\ModelConfigurationFilterHook;
use App\Support\ConfigurationKeys;

return [
    'register_routes' => true,
    'route_prefix' => env('MODEL_CONFIG_ROUTE_PREFIX', 'api/v1'),
    'auth_middleware' => ['auth:sanctum'],
    'allow_case_insensitive_keys' => false,
    'allowed_keys' => ConfigurationKeys::RULES,
    'model' => \App\Models\Configuration::class,
    'hooks' => [ModelConfigurationFilterHook::class],
];
