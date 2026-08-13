<?php

/**
 * Overrides for whilesmart/eloquent-entitlements. Anything not set here keeps
 * the package default.
 */
return [
    // Core has no plans of its own and allows every feature, so the snapshot
    // endpoint would report no plan while the gate says yes. A deployment that
    // binds a real implementation turns the routes on.
    'register_routes' => env('ENTITLEMENTS_REGISTER_ROUTES', false),

    'route_prefix' => env('ENTITLEMENTS_ROUTE_PREFIX', 'api/v1'),
];
