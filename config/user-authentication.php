<?php

return [
    'user_model' => Whilesmart\UserAuthentication\Models\User::class,
    'register_routes' => true,
    'register_oauth_routes' => true,
    'route_prefix' => env('USER_AUTH_ROUTE_PREFIX', 'api'),

    'response_formatter' => \Whilesmart\UserAuthentication\ResponseFormatters\DefaultResponseFormatter::class,

    'middleware_hooks' => [
        \App\Hooks\SetAvatar::class,
    ],

    // Verification requirements before registration
    'verification' => [
        'require_email_verification' => env('USER_AUTH_REQUIRE_EMAIL_VERIFICATION', false),  // Set to true to require email verification before registration
        'require_phone_verification' => env('USER_AUTH_REQUIRE_PHONE_VERIFICATION', false),  // Set to true to require phone verification before registration
        'code_length' => env('USER_AUTH_CODE_LENGTH', 6),                     // Length of verification codes
        'code_expiry_minutes' => env('USER_AUTH_CODE_EXPIRY_MINUTES', 5),            // How long codes are valid
        'rate_limit_attempts' => env('USER_AUTH_RATE_LIMIT_ATTEMPTS', 3),             // Number of attempts before rate limiting
        'rate_limit_minutes' => env('USER_AUTH_RATE_LIMIT_MINUTES', 5),              // Rate limit window in minutes
        'provider' => env('USER_AUTH_VERIFICATION_PROVIDER', 'default'),                // Verification provider: 'default', 'smartpings'
        'self_managed' => env('USER_AUTH_SELF_MANAGED', true),                 // Set to false to let the provider handle the entire flow
    ],

    // SmartPings configuration
    'smartpings' => [
        'client_id' => env('SMARTPINGS_CLIENT_ID'),
        'secret_id' => env('SMARTPINGS_SECRET_ID'),
    ],
];
