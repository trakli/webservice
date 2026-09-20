<?php

use App\Engagement\AccountsMetricProvider;
use App\Engagement\AgentUsageMetricProvider;
use App\Engagement\DemographicsMetricProvider;
use App\Engagement\EngagementMetricProvider;
use App\Engagement\TransactionsMetricProvider;
use App\Engagement\UsersMetricProvider;
use Whilesmart\Engagement\Providers\VisitorMetricProvider;

return [
    // The admin metrics endpoint is served by the application's own admin route
    // (role:admin gated), so the package route stays off.
    'register_routes' => env('ENGAGEMENT_REGISTER_ROUTES', false),
    'register_report_route' => env('ENGAGEMENT_REGISTER_REPORT_ROUTE', false),
    'register_ingest_route' => env('ENGAGEMENT_REGISTER_INGEST_ROUTE', true),
    'route_prefix' => env('ENGAGEMENT_ROUTE_PREFIX', 'api/v1'),
    'route_middleware' => ['api', 'auth:sanctum'],
    'ingest_route_middleware' => ['api', 'throttle:60,1'],
    'max_batch_size' => 20,
    'clients' => [
        'dashboard' => [
            'name' => 'Dashboard',
            'site_key' => env('ENGAGEMENT_DASHBOARD_SITE_KEY', ''),
            'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('ENGAGEMENT_DASHBOARD_ORIGINS', ''))))),
        ],
        'website' => [
            'name' => 'Website',
            'site_key' => env('ENGAGEMENT_WEBSITE_SITE_KEY', ''),
            'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('ENGAGEMENT_WEBSITE_ORIGINS', ''))))),
        ],
    ],

    'events_table' => env('ENGAGEMENT_EVENTS_TABLE', 'engagement_events'),

    'providers' => [
        UsersMetricProvider::class,
        TransactionsMetricProvider::class,
        EngagementMetricProvider::class,
        DemographicsMetricProvider::class,
        AccountsMetricProvider::class,
        AgentUsageMetricProvider::class,
        VisitorMetricProvider::class,
    ],
];
