<?php

namespace App\Outreach;

use Illuminate\Database\Eloquent\Model;
use Whilesmart\Outreach\Contracts\MessageRenderer;
use Whilesmart\Outreach\Models\Outreach;
use Whilesmart\Outreach\Support\RenderedMessage;

class UserMessageRenderer implements MessageRenderer
{
    public function render(Outreach $outreach, Model $recipient): RenderedMessage
    {
        return new RenderedMessage(
            (string) ($recipient->getAttribute('email') ?? ''),
            $outreach->subject !== null ? $this->personalize($outreach->subject, $recipient) : null,
            $this->personalize((string) $outreach->body, $recipient),
            $outreach->cta_label,
            $outreach->cta_url,
        );
    }

    private function personalize(string $text, Model $recipient): string
    {
        $first = (string) ($recipient->getAttribute('first_name') ?? '');
        $last = (string) ($recipient->getAttribute('last_name') ?? '');
        $name = trim("$first $last");

        return strtr($text, [
            '{{first_name}}' => $first,
            '{{last_name}}' => $last,
            '{{name}}' => $name !== '' ? $name : $first,
            '{{email}}' => (string) ($recipient->getAttribute('email') ?? ''),
        ]);
    }
}
