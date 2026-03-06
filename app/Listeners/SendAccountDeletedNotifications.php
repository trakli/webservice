<?php

namespace App\Listeners;

use App\Events\AccountDeleted;
use App\Mail\AccountDeletedMail;
use App\Mail\GenericMail;
use Illuminate\Support\Facades\Mail;

class SendAccountDeletedNotifications
{
    public function handle(AccountDeleted $event): void
    {
        try {
            Mail::to($event->email)->send(new AccountDeletedMail($event->userName));
            Mail::to('accountdeleted@trakli.app')->send(new GenericMail(
                "Account Deleted ({$event->source}): {$event->userName}",
                "User: {$event->userName} ({$event->email})\n\nReason: {$event->reason}"
            ));
        } catch (\Throwable $e) {
            logger()->error('Account deletion mail failed', [
                'email' => $event->email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
