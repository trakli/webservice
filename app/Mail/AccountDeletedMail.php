<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountDeletedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $userName
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('app.name')),
            subject: 'Your Trakli Account Has Been Deleted'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account-deleted',
            text: 'emails.account-deleted-text',
            with: ['userName' => $this->userName]
        );
    }
}
