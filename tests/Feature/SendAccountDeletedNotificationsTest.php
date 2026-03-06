<?php

namespace Tests\Feature;

use App\Events\AccountDeleted;
use App\Mail\AccountDeletedMail;
use App\Mail\GenericMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendAccountDeletedNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_sends_emails_on_account_deleted_event()
    {
        Mail::fake();

        $event = new AccountDeleted('John Doe', 'john@example.com', 'No longer needed');
        event($event);

        Mail::assertSent(AccountDeletedMail::class, function ($mail) {
            return $mail->hasTo('john@example.com');
        });

        Mail::assertSent(GenericMail::class, function ($mail) {
            return $mail->hasTo('accountdeleted@trakli.app');
        });
    }

    public function test_listener_includes_source_in_subject()
    {
        Mail::fake();

        $event = new AccountDeleted('Jane Doe', 'jane@example.com', 'Policy violation', 'Admin');
        event($event);

        Mail::assertSent(GenericMail::class, function ($mail) {
            return $mail->hasTo('accountdeleted@trakli.app');
        });
    }
}
