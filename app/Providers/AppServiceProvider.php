<?php

namespace App\Providers;

use App\Contracts\Entitlements;
use App\Contracts\OwnerResolver;
use App\Services\DocumentProcessorManager;
use App\Services\DocumentProcessors\CsvProcessor;
use App\Services\DocumentProcessors\RemoteDocumentProcessor;
use App\Services\IntegrationRegistry;
use App\Services\SchemaConformance\SchemaConformanceService;
use App\Support\AllowAllEntitlements;
use App\Support\UserOwnerResolver;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
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

        $this->app->singleton(OwnerResolver::class, UserOwnerResolver::class);

        $this->app->singleton(Entitlements::class, AllowAllEntitlements::class);

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

        // Every successful migrate/migrate:fresh run is followed by a
        // silent `schema:conform` so the declared spec in config/schema.php
        // is always the source of truth — no defensive migrations needed.
        Event::listen(MigrationsEnded::class, function () {
            try {
                app(SchemaConformanceService::class)->conform();
            } catch (\Throwable $e) {
                logger()->warning('Schema auto-conform skipped: ' . $e->getMessage());
            }
        });
    }
}
