<?php

namespace App\Observers;

use App\Events\ChatTurnEvent;
use App\Models\ChatMessage;

/**
 * Pushes a "settled" event the moment an assistant reply reaches a terminal
 * state, so a subscribed client swaps the live progress view for the result
 * without waiting on the next poll. Covers every answer path (agent, general,
 * data) uniformly, since they all land here on the same status change.
 */
class ChatMessageObserver
{
    public function updated(ChatMessage $message): void
    {
        if ($message->role !== ChatMessage::ROLE_ASSISTANT) {
            return;
        }

        if (! in_array($message->status, [ChatMessage::STATUS_COMPLETED, ChatMessage::STATUS_FAILED], true)) {
            return;
        }

        if (! $message->wasChanged('status')) {
            return;
        }

        try {
            ChatTurnEvent::dispatch($message->chat_session_id, $message->id, 'settled');
        } catch (\Throwable $e) {
            // A down broadcaster must never break saving the message.
        }
    }
}
