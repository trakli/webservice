<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Auth\McpGateRegistrar;
use App\Mcp\Plugins\McpPluginManager;
use App\Mcp\Server\TrakliMcpServer;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

class McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/mcp.php', 'mcp');

        $this->app->singleton(McpPluginManager::class, fn () => new McpPluginManager());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Console\McpPluginsCommand::class]);

            $this->publishes([
                __DIR__ . '/../../config/mcp.php' => config_path('mcp.php'),
            ], 'mcp-config');
        }

        McpGateRegistrar::register();

        if (! config('mcp.enabled', false)) {
            return;
        }

        $this->registerRoutes();
    }

    /**
     * Register the MCP endpoint on the package's own Streamable-HTTP transport.
     * Clients authenticate with a Sanctum bearer token; the returned route lets
     * us layer the app's guard and rate limit on top.
     */
    protected function registerRoutes(): void
    {
        $middleware = ['auth:' . config('mcp.auth.guard', 'sanctum')];

        if (config('mcp.rate_limit.enabled', true)) {
            $middleware[] = 'throttle:'
                . config('mcp.rate_limit.max_requests', 60)
                . ',' . config('mcp.rate_limit.decay_minutes', 1);
        }

        Mcp::web(config('mcp.endpoint', 'mcp'), TrakliMcpServer::class)
            ->middleware($middleware);
    }
}
