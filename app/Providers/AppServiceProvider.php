<?php

namespace App\Providers;

use App\Contracts\OwnerResolver;
use App\Services\DocumentProcessorManager;
use App\Services\DocumentProcessors\CsvProcessor;
use App\Services\DocumentProcessors\RemoteDocumentProcessor;
use App\Services\IntegrationRegistry;
use App\Support\UserOwnerResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Factory;
use WhileSmart\LaravelPluginEngine\Providers\PluginServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->register(PluginServiceProvider::class);
        $this->app->register(\App\Mcp\McpServiceProvider::class);

        $this->app->singleton(OwnerResolver::class, UserOwnerResolver::class);

        $this->app->bind(
            \Whilesmart\OwnerAccess\Contracts\OwnerAuthorizer::class,
            \App\Holdings\UserOwnerAuthorizer::class
        );
        $this->app->bind(
            \Whilesmart\Holdings\Contracts\HoldingPriceProvider::class,
            \App\Holdings\CoingeckoPriceProvider::class
        );

        $this->app->singleton(IntegrationRegistry::class);

        $this->app->singleton(DocumentProcessorManager::class, function ($app) {
            $manager = new DocumentProcessorManager();
            $manager->register($app->make(CsvProcessor::class));
            $manager->register($app->make(RemoteDocumentProcessor::class));

            return $manager;
        });

        $this->app->bind(Messaging::class, function () {
            $credentials = config('firebase.credentials') ?? env('FIREBASE_CREDENTIALS');
            if (empty($credentials) || ! file_exists(base_path($credentials))) {
                return null;
            }

            $factory = (new Factory())
                ->withServiceAccount(base_path($credentials));

            return $factory->createMessaging();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Carbon::serializeUsing(fn ($carbon) => $carbon->format('Y-m-d\TH:i:s\Z'));
    }
}
