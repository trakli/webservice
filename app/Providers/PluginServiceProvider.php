<?php

namespace App\Providers;

use App\Services\PluginManager;
use Illuminate\Support\ServiceProvider;

class PluginServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PluginManager::class, function ($app) {
            return new PluginManager($app);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\Plugin\ListCommand::class,
                \App\Console\Commands\Plugin\EnableCommand::class,
                \App\Console\Commands\Plugin\DisableCommand::class,
                \App\Console\Commands\Plugin\InstallCommand::class,
                \App\Console\Commands\Plugin\DiscoverCommand::class,
                \App\Console\Commands\Plugin\InfoCommand::class,
            ]);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(PluginManager $pluginManager)
    {
        $pluginManager->registerPlugins();
    }

    /**
     * Register plugin migrations.
     */
    protected function registerMigrations()
    {
        // To be implemented by plugins
    }
}
