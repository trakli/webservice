<?php

namespace App\Providers;

use App\Outreach\MailChannel;
use App\Outreach\UserAudienceResolver;
use App\Outreach\UserMessageRenderer;
use Illuminate\Support\ServiceProvider;
use Whilesmart\Outreach\Contracts\AudienceResolver;
use Whilesmart\Outreach\Contracts\MessageRenderer;
use Whilesmart\Outreach\Contracts\OutreachChannel;

class OutreachServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AudienceResolver::class, UserAudienceResolver::class);
        $this->app->bind(MessageRenderer::class, UserMessageRenderer::class);
        $this->app->bind(OutreachChannel::class, MailChannel::class);
    }
}
