<?php

namespace Trakli\Example;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ExampleServiceProvider extends ServiceProvider
{
    protected $namespace = 'Trakli\\Example\\Http\\Controllers';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register any bindings here if needed
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerRoutes();
    }

    /**
     * Register the plugin routes.
     */
    protected function registerRoutes(): void
    {
        Route::middleware(['api'])
            ->prefix('api/example')
            ->namespace($this->namespace)
            ->group(function () {
                require base_path('plugins/example/routes/api.php');
            });
    }
}
