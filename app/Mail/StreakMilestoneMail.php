<?php

namespace App\Mail;

use App\Models\Streak;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StreakMilestoneMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Streak $streak,
        public int $milestone,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('app.name')),
            subject: __(':count :period of :activity in a row', [
                'count' => $this->milestone,
                'period' => $this->periodLabel(),
                'activity' => $this->streak->type->label(),
            ])
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.streak-milestone',
            text: 'emails.streak-milestone-text',
            with: [
                'streak' => $this->streak,
                'milestone' => $this->milestone,
                'periodLabel' => $this->periodLabel(),
                'activity' => $this->streak->type->label(),
                'longest' => $this->streak->longest_length,
            ]
        );
    }

    private function periodLabel(): string
    {
        $label = $this->streak->period->label();

        return $this->milestone === 1 ? $label : $label . 's';
    }
}
