<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use WhileSmart\LaravelPluginEngine\Providers\PluginServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->register(PluginServiceProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
