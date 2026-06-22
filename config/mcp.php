<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MCP Server Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Trakli MCP Server that exposes financial data
    | and operations to MCP-compatible AI clients.
    |
    */

    'enabled' => env('MCP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Inspection Endpoint
    |--------------------------------------------------------------------------
    |
    | When enabled, a GET /mcp/inspect endpoint is registered that returns
    | all registered tools, resources, prompts, and plugin metadata.
    | Disable in production to avoid leaking implementation details.
    |
    */

    'inspect_enabled' => env('MCP_INSPECT_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Server Endpoint
    |--------------------------------------------------------------------------
    |
    | The endpoint path for the MCP SSE transport.
    |
    */

    'endpoint' => env('MCP_ENDPOINT', '/mcp/sse'),

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | Authentication guard to use for MCP connections. Uses Sanctum tokens
    | by default for API authentication.
    |
    */

    'auth' => [
        'guard' => env('MCP_AUTH_GUARD', 'sanctum'),

        /*
        |--------------------------------------------------------------------------
        | Strict Permission Mode
        |--------------------------------------------------------------------------
        |
        | When enabled, plugins that declare permissions without matching
        | Gate definitions will throw exceptions at registration time.
        | When disabled, warnings are logged instead.
        |
        */

        'strict_permissions' => env('MCP_STRICT_PERMISSIONS', false),

        /*
        |--------------------------------------------------------------------------
        | Custom Permission Gates
        |--------------------------------------------------------------------------
        |
        | Override the default permission-to-gate mapping with custom
        | authorization logic. Each key is a permission name, each value
        | is a closure (User $user → bool) or a Gate@policy string.
        |
        */

        'permission_gates' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Enabled Tools
    |--------------------------------------------------------------------------
    |
    | Control which tool categories are exposed via MCP.
    |
    */

    'tools' => [
        'transactions' => env('MCP_TOOLS_TRANSACTIONS', true),
        'budgets' => env('MCP_TOOLS_BUDGETS', true),
        'wallets' => env('MCP_TOOLS_WALLETS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Rate limit for MCP requests per minute.
    |
    */

    'rate_limit' => [
        'enabled' => env('MCP_RATE_LIMIT_ENABLED', true),
        'max_requests' => env('MCP_RATE_LIMIT_MAX', 60),
        'decay_minutes' => env('MCP_RATE_LIMIT_DECAY', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Metadata
    |--------------------------------------------------------------------------
    |
    | Metadata for the MCP server identification.
    |
    */

    'server' => [
        'name' => env('MCP_SERVER_NAME', 'Trakli'),
        'version' => env('MCP_SERVER_VERSION', '1.0.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Plugin Configuration
    |--------------------------------------------------------------------------
    |
    | List of MCP plugin classes to load. Plugins can also be auto-discovered
    | from installed packages that declare an 'mcp-plugin' extra in composer.json.
    |
    */

    'plugins' => env('MCP_PLUGINS')
        ? array_filter(array_map('trim', explode(',', env('MCP_PLUGINS'))))
        : [],

    /*
    |--------------------------------------------------------------------------
    | Permission Definitions
    |--------------------------------------------------------------------------
    |
    | Define permissions that plugins can request. Each permission maps to
    | a Laravel Gate/Policy for authorization checks.
    |
    */

    'permissions' => [
        'transactions.read' => [
            'description' => 'Read access to transactions',
            'gate' => 'transactions.view',
        ],
        'transactions.write' => [
            'description' => 'Create/update/delete transactions',
            'gate' => 'transactions.manage',
        ],
        'budgets.read' => [
            'description' => 'Read access to budgets',
            'gate' => 'budgets.view',
        ],
        'budgets.write' => [
            'description' => 'Create/update/delete budgets',
            'gate' => 'budgets.manage',
        ],
        'wallets.read' => [
            'description' => 'Read access to wallets',
            'gate' => 'wallets.view',
        ],
        'wallets.write' => [
            'description' => 'Create/update/delete wallets',
            'gate' => 'wallets.manage',
        ],
        'categories.read' => [
            'description' => 'Read access to categories',
            'gate' => 'categories.view',
        ],
        'categories.write' => [
            'description' => 'Create/update/delete categories',
            'gate' => 'categories.manage',
        ],
        'reports.read' => [
            'description' => 'Read access to financial reports',
            'gate' => 'reports.view',
        ],
        'insights.read' => [
            'description' => 'Read access to transaction insights',
            'gate' => 'insights.view',
        ],
    ],

];