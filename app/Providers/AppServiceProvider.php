<?php

namespace App\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Contract\Messaging;
use WhileSmart\LaravelPluginEngine\Providers\PluginServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->register(PluginServiceProvider::class);

        $this->app->bind(Messaging::class, function () {
            $credentials = config('firebase.credentials') ?? env('FIREBASE_CREDENTIALS');
            if (empty($credentials) || ! file_exists(base_path($credentials))) {
                return null;
            }

            $factory = (new \Kreait\Firebase\Factory())
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
