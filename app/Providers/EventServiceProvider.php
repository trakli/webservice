<?php

namespace App\Providers;

use App\Events\AccountDeleted;
use App\Events\BudgetForecastBreached;
use App\Events\BudgetThresholdBreached;
use App\Events\TransactionRecorded;
use App\Listeners\CreateBudgetAlertReminder;
use App\Listeners\PasswordResetCodeGeneratedListener;
use App\Listeners\PasswordResetCompleteListener;
use App\Listeners\QueueBudgetRecompute;
use App\Listeners\SendAccountDeletedNotifications;
use App\Listeners\UserRegistered;
use App\Models\Transaction;
use App\Observers\TransactionObserver;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Whilesmart\UserAuthentication\Events\PasswordResetCodeGeneratedEvent;
use Whilesmart\UserAuthentication\Events\PasswordResetCompleteEvent;
use Whilesmart\UserAuthentication\Events\UserRegisteredEvent;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        PasswordResetCodeGeneratedEvent::class => [
            PasswordResetCodeGeneratedListener::class,
        ],
        PasswordResetCompleteEvent::class => [
            PasswordResetCompleteListener::class,
        ],
        UserRegisteredEvent::class => [
            UserRegistered::class,
        ],
        AccountDeleted::class => [
            SendAccountDeletedNotifications::class,
        ],
        TransactionRecorded::class => [
            QueueBudgetRecompute::class,
        ],
        BudgetThresholdBreached::class => [
            [CreateBudgetAlertReminder::class, 'handleThreshold'],
        ],
        BudgetForecastBreached::class => [
            [CreateBudgetAlertReminder::class, 'handleForecast'],
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        Transaction::observe(TransactionObserver::class);
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
