<?php

return [
    'register_routes' => env('HOLDINGS_REGISTER_ROUTES', true),
    'route_prefix' => env('HOLDINGS_ROUTE_PREFIX', 'api/v1'),
    'route_middleware' => ['api', 'auth:sanctum'],
    'holdings_table' => env('HOLDINGS_TABLE', 'holdings'),

    // Default fiat currency for new holdings when none is supplied.
    'default_currency' => env('HOLDINGS_DEFAULT_CURRENCY', 'USD'),
];
