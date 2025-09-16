<?php

namespace App\Listeners;

use App\Services\NotificationService;
use Whilesmart\UserAuthentication\Events\PasswordResetCompleteEvent;

class PasswordResetCompleteListener
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
    public function handle(PasswordResetCompleteEvent $event): void
    {
        $this->notificationService->sendEmailNotification([
            'to' => $event->user->email,
            'subject' => __('Your password was changed'),
            'body' => __('Password has been reset successfully. If you did nt make this change, please contact us.'),
        ]);
    }
}
