<?php

use App\Engagement\AccountsMetricProvider;
use App\Engagement\DemographicsMetricProvider;
use App\Engagement\EngagementMetricProvider;
use App\Engagement\TransactionsMetricProvider;
use App\Engagement\UsersMetricProvider;

return [
    // The admin metrics endpoint is served by the application's own admin route
    // (role:admin gated), so the package route stays off.
    'register_routes' => env('ENGAGEMENT_REGISTER_ROUTES', false),
    'route_prefix' => env('ENGAGEMENT_ROUTE_PREFIX', 'api'),
    'route_middleware' => ['api', 'auth:sanctum'],

    'events_table' => env('ENGAGEMENT_EVENTS_TABLE', 'engagement_events'),

    'providers' => [
        UsersMetricProvider::class,
        TransactionsMetricProvider::class,
        EngagementMetricProvider::class,
        DemographicsMetricProvider::class,
        AccountsMetricProvider::class,
    ],
];
