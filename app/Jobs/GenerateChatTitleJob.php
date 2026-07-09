<?php

namespace App\Jobs;

use App\Models\ChatSession;
use App\Services\AiRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Names a chat session from its opening question. Runs on its own so the extra
 * model call never sits on the answer's critical path: the assistant reply is
 * already saved by the time this fires.
 */
class GenerateChatTitleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 60;

    public int $tries = 2;

    public function __construct(public ChatSession $session, public string $firstQuestion)
    {
    }

    public function handle(AiRouter $router): void
    {
        if ($this->session->fresh()?->title !== null) {
            return;
        }

        $this->session->update([
            'title' => $router->generateTitle($this->firstQuestion) ?? Str::limit($this->firstQuestion, 60),
        ]);
    }
}
