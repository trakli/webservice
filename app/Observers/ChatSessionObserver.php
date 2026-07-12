<?php

namespace App\Observers;

use App\Models\AgentProposedAction;
use App\Models\ChatSession;

class ChatSessionObserver
{
    /**
     * Discard proposals raised in a chat when the chat is deleted. They point at
     * the session through the package's polymorphic source, which carries no
     * database foreign key, so the cascade is ours to run.
     */
    public function deleting(ChatSession $session): void
    {
        AgentProposedAction::query()
            ->where('source_type', $session->getMorphClass())
            ->where('source_id', $session->getKey())
            ->forceDelete();
    }
}
