<?php

return [
    'user_model' => App\Models\User::class,
    'register_routes' => true,
    'register_oauth_routes' => true,
    'route_prefix' => 'api/v1',

    'response_formatter' => \Whilesmart\UserAuthentication\ResponseFormatters\DefaultResponseFormatter::class,

    'middleware_hooks' => [
        // Add your middleware hook classes here
        \App\Hooks\SetAvatar::class,
    ],

    // Verification requirements before registration
    'verification' => [
        'require_email_verification' => false,  // Set to true to require email verification before registration
        'require_phone_verification' => false,  // Set to true to require phone verification before registration
        'code_length' => 6,                     // Length of verification codes
        'code_expiry_minutes' => 5,            // How long codes are valid
        'rate_limit_attempts' => 3,             // Number of attempts before rate limiting
        'rate_limit_minutes' => 5,              // Rate limit window in minutes
        'provider' => 'smartpings',                // Verification provider: 'default', 'smartpings'
        'self_managed' => false,                 // Set to false to let the provider handle the entire flow
    ],

    // SmartPings configuration
    'smartpings' => [
        'client_id' => env('SMARTPINGS_CLIENT_ID'),
        'secret_id' => env('SMARTPINGS_SECRET_ID'),
    ],
];
