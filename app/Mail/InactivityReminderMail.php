<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InactivityReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public array $tier,
        public int $daysInactive
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('app.name')),
            subject: $this->tier['subject']
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.inactivity-reminder',
            with: [
                'user' => $this->user,
                'tier' => $this->tier,
                'daysInactive' => $this->daysInactive,
            ]
        );
    }
}
