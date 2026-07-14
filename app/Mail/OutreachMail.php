<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class OutreachMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, array{path: string, name: string, mime: ?string}>  $files
     */
    public function __construct(
        public $subject,
        public string $body,
        public ?string $ctaLabel = null,
        public ?string $ctaUrl = null,
        public ?string $imageUrl = null,
        public array $files = [],
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('app.name')),
            subject: $this->subject
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.outreach',
            with: [
                'subject' => $this->subject,
                'bodyHtml' => Str::markdown($this->body, [
                    'html_input' => 'strip',
                    'allow_unsafe_links' => false,
                ]),
                'ctaLabel' => $this->ctaLabel,
                'ctaUrl' => $this->ctaUrl,
                'imageUrl' => $this->imageUrl,
            ]
        );
    }

    /**
     * @return Attachment[]
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $file) => Attachment::fromPath($file['path'])
                ->as($file['name'])
                ->withMime($file['mime'] ?? 'application/octet-stream'),
            $this->files
        );
    }
}
