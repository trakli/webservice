<?php

namespace App\Listeners;

use App\Services\NotificationService;
use Whilesmart\LaravelUserAuthentication\Events\PasswordResetCodeGeneratedEvent;

class PasswordResetCodeGeneratedListener
{
    private NotificationService $notificationService;

    /**
     * Create the event listener.
     */
    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Handle the event.
     */
    public function handle(PasswordResetCodeGeneratedEvent $event): void
    {

        $this->notificationService->sendEmailNotification([
            'to' => $event->email,
            'subject' => __('Password Reset Code'),
            'body' => __('Your password reset code for Trakli is: ').$event->code,
        ]);
    }
}
