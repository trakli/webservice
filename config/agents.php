<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default provider and model
    |--------------------------------------------------------------------------
    |
    | The Prism provider and model a harness uses when it does not specify its
    | own. Credentials are read by Prism from each provider's native config
    | (config/prism.php), which in turn reads provider-native env vars such as
    | GEMINI_API_KEY, ANTHROPIC_API_KEY, OPENAI_API_KEY, GROQ_API_KEY. This
    | package never introduces a generic single-key variable.
    |
    */
    'provider' => env('AGENTS_PROVIDER', env('LLM_PROVIDER', 'gemini')),
    // The agent needs reliable tool-calling. gemini-2.5-flash returns empty
    // candidates when tools are attached, so the agent uses its own model
    // (decoupled from LLM_MODEL, which SmartQL uses); override with AGENTS_MODEL.
    'model' => env('AGENTS_MODEL', 'gemini-flash-latest'),
    'temperature' => (float) env('AGENTS_TEMPERATURE', env('LLM_TEMPERATURE', 0)),

    /*
    |--------------------------------------------------------------------------
    | Agent loop step cap
    |--------------------------------------------------------------------------
    |
    | Hard upper bound on the number of tool-calling steps any harness may run,
    | regardless of what the harness requests. Bounds runaway cost.
    |
    */
    'max_steps' => (int) env('AGENTS_MAX_STEPS', 16),

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */
    'register_routes' => env('AGENTS_REGISTER_ROUTES', false),
    'route_prefix' => env('AGENTS_ROUTE_PREFIX', 'api'),
    'route_middleware' => ['api', 'auth:sanctum'],

    /*
    |--------------------------------------------------------------------------
    | Tools
    |--------------------------------------------------------------------------
    |
    | Tool classes registered into the ToolRegistry on top of the package
    | defaults. Each must implement Whilesmart\Agents\Contracts\Tool (extend
    | Whilesmart\Agents\Tools\AbstractTool). Apps add their own here, or call
    | Agents::registerTool() at runtime.
    |
    */
    'tools' => [
        App\Ai\Tools\Read\SmartqlQueryTool::class,
        App\Ai\Tools\Read\GetStatsTool::class,
        App\Ai\Tools\Read\ListWalletsTool::class,
        App\Ai\Tools\Read\ListCategoriesTool::class,
        App\Ai\Tools\Read\ListPartiesTool::class,
        App\Ai\Tools\Render\RenderKpiTool::class,
        App\Ai\Tools\Render\RenderChartTool::class,
        App\Ai\Tools\Render\RenderTableTool::class,
        App\Ai\Tools\Render\RenderMarkdownTool::class,
        App\Ai\Tools\Render\RenderCalloutTool::class,
        App\Ai\Tools\Render\RenderTimelineTool::class,
        App\Ai\Tools\Render\RenderProgressTool::class,
        App\Ai\Tools\Render\AskQuestionTool::class,
        App\Ai\Tools\Render\OpenCanvasTool::class,
        App\Ai\Tools\Render\UpdateCanvasTool::class,
        App\Ai\Tools\Write\RecordTransactionTool::class,
        App\Ai\Tools\Write\RecordTransferTool::class,
        App\Ai\Tools\Write\CreateWalletTool::class,
        App\Ai\Tools\Write\CreateCategoryTool::class,
        App\Ai\Tools\Write\CreatePartyTool::class,
        App\Ai\Tools\Write\CategorizeTransactionTool::class,
        App\Ai\Tools\Write\AttachToTransactionTool::class,
        App\Ai\Tools\Documents\ImportDocumentTool::class,
        App\Ai\Tools\Documents\ExtractReceiptTool::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-discovery
    |--------------------------------------------------------------------------
    |
    | When enabled, every concrete AbstractTool under the given namespace/path
    | is registered automatically, in addition to the `tools` list above.
    |
    */
    'discovery' => [
        'enabled' => env('AGENTS_DISCOVERY', false),
        'namespace' => 'App\\Ai\\Tools',
        'path' => app()->basePath('app/Ai/Tools'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Harnesses
    |--------------------------------------------------------------------------
    |
    | Named agent configurations. A value may be a class implementing
    | Whilesmart\Agents\Contracts\Harness, or an array consumed by
    | GenericHarness with keys: prompt, tools, provider, model, temperature,
    | max_steps, permissions.
    |
    */
    'harnesses' => [
        'trakli' => App\Ai\Harnesses\TrakliHarness::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt overrides
    |--------------------------------------------------------------------------
    |
    | Inline overrides for named prompts, keyed by prompt name. A published
    | resources/prompts/{name}.md file also overrides the package default.
    |
    */
    'prompts' => [
        // 'finance-assistant' => 'You are a careful finance assistant...',
    ],

    /*
    |--------------------------------------------------------------------------
    | Built-in tool configuration
    |--------------------------------------------------------------------------
    */
    'eloquent' => [
        // Per-model allowlist. Keys are tool-facing aliases; values declare the
        // model, the column the agent may read/write, and the column scoping
        // rows to the acting user. Nothing is queryable unless listed here.
        'models' => [
            // 'transactions' => [
            //     'model' => App\Models\Transaction::class,
            //     'owner_key' => 'user_id',
            //     'readable' => ['amount', 'type', 'description', 'created_at'],
            //     'writable' => ['amount', 'type', 'description'],
            // ],
        ],
        'max_rows' => (int) env('AGENTS_ELOQUENT_MAX_ROWS', 50),
        'allow_writes' => env('AGENTS_ELOQUENT_ALLOW_WRITES', false),
    ],

    'http' => [
        'allowed_hosts' => array_filter(explode(',', (string) env('AGENTS_HTTP_ALLOWED_HOSTS', ''))),
        'timeout' => (int) env('AGENTS_HTTP_TIMEOUT', 10),
        'max_bytes' => (int) env('AGENTS_HTTP_MAX_BYTES', 100_000),
    ],

    'web_search' => [
        'driver' => env('AGENTS_WEB_SEARCH_DRIVER', 'null'),
        'max_results' => (int) env('AGENTS_WEB_SEARCH_MAX_RESULTS', 5),
    ],

    'storage' => [
        'disk' => env('AGENTS_STORAGE_DISK', 'local'),
        'path_prefix' => env('AGENTS_STORAGE_PREFIX', ''),
        'max_bytes' => (int) env('AGENTS_STORAGE_MAX_BYTES', 100_000),
    ],
];
