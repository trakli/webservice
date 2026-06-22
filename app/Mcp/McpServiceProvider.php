<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Auth\McpGateRegistrar;
use App\Mcp\Plugins\McpPluginManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/mcp.php', 'mcp');

        // Register the plugin manager as a singleton
        $this->app->singleton(McpPluginManager::class, function () {
            return new McpPluginManager();
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\McpPluginsCommand::class,
            ]);
        }

        $this->registerGates();

        if (! config('mcp.enabled', true)) {
            return;
        }

        $this->registerRoutes();
        $this->publishConfig();
    }

    /**
     * Register MCP permission Gates from configuration.
     */
    protected function registerGates(): void
    {
        McpGateRegistrar::register();
    }

    protected function registerRoutes(): void
    {
        $middleware = ['auth:' . config('mcp.auth.guard', 'sanctum')];

        if (config('mcp.rate_limit.enabled', true)) {
            $middleware[] = 'throttle:' . config('mcp.rate_limit.max_requests', 60) . ',' . config('mcp.rate_limit.decay_minutes', 1);
        }

        Route::prefix('mcp')
            ->middleware($middleware)
            ->group(function () {
                Route::get('sse', [\App\Mcp\Http\Controllers\McpController::class, 'handle'])
                    ->name('mcp.sse');

                Route::post('sse', [\App\Mcp\Http\Controllers\McpController::class, 'handle'])
                    ->name('mcp.sse.post');

                Route::post('initialize', [\App\Mcp\Http\Controllers\McpController::class, 'initialize'])
                    ->name('mcp.initialize');

                // Inspection endpoint — only available when explicitly enabled.
                if (config('mcp.inspect_enabled', false)) {
                    Route::get('inspect', [\App\Mcp\Http\Controllers\McpController::class, 'inspect'])
                        ->name('mcp.inspect');
                }
            });
    }

    protected function publishConfig(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/mcp.php' => config_path('mcp.php'),
            ], 'mcp-config');
        }
    }
}
