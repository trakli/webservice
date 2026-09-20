<?php

use App\Models\User;

return [
    'route_prefix' => 'api/v1/admin',
    'route_middleware' => ['api', 'auth:sanctum', 'role:admin'],
    'user_model' => User::class,
    'user_search_columns' => ['first_name', 'last_name', 'email', 'username'],
    'owner' => ['type' => 'platform', 'id' => 0],
    'templates' => [
        'welcome' => [
            'name' => 'Welcome email',
            'description' => 'Sent after a user completes registration.',
            'enabled' => true,
            'subject' => 'Welcome to Trakli, {{first_name}}',
            'body' => "Hi {{first_name}},\n\nWelcome to Trakli. You can now keep your money organized in one place.",
            'cta_label' => 'Open Trakli',
            'cta_url' => 'https://app.trakli.com',
        ],
    ],
];
