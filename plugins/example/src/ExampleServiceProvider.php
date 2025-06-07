<?php

namespace Trakli\ExamplePlugin;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ExampleServiceProvider extends ServiceProvider
{
    protected string $pluginName = 'example';

    protected string $namespace = 'Trakli\\ExamplePlugin\\Http\\Controllers';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register any bindings
        $this->app->bind('example', function () {
            return new \stdClass; // Replace with actual class if needed
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load routes
        $this->registerRoutes();

        // Load views
        $this->loadViewsFrom(__DIR__.'/../resources/views', $this->pluginName);

        // Publish assets
        $this->publishes([
            __DIR__.'/../resources/assets' => public_path('vendor/'.$this->pluginName),
        ], 'public');
    }

    /**
     * Register the plugin routes.
     */
    protected function registerRoutes(): void
    {
        $routesPath = base_path('plugins/example/routes/web.php');

        if (file_exists($routesPath)) {
            Route::middleware(['web', 'api'])
                ->prefix('api/example')
                ->namespace($this->namespace)
                ->group(function () use ($routesPath) {
                    require $routesPath;
                });

            Log::debug("Registered routes for plugin: {$this->pluginName}");
        } else {
            Log::warning("Routes file not found for plugin: {$this->pluginName} at {$routesPath}");
        }
    }
}
