<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InsightsMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public array $insights,
        public string $periodLabel
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('app.name')),
            subject: "Your {$this->periodLabel} Financial Insights"
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.insights',
            with: [
                'user' => $this->user,
                'insights' => $this->insights,
                'periodLabel' => $this->periodLabel,
            ]
        );
    }
}
