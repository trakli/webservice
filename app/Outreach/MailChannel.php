<?php

namespace App\Outreach;

use App\Mail\OutreachMail;
use Illuminate\Support\Facades\Mail;
use Whilesmart\Outreach\Contracts\OutreachChannel;
use Whilesmart\Outreach\Models\Delivery;
use Whilesmart\Outreach\Support\RenderedMessage;

class MailChannel implements OutreachChannel
{
    public function key(): string
    {
        return 'email';
    }

    public function deliver(Delivery $delivery, RenderedMessage $message): void
    {
        $metadata = $delivery->outreach->metadata ?? [];

        Mail::to($message->to)->queue(new OutreachMail(
            $message->subject,
            $message->body,
            $message->ctaLabel,
            $message->ctaUrl,
            $metadata['image_url'] ?? null,
            $metadata['attachments'] ?? [],
        ));
    }
}
