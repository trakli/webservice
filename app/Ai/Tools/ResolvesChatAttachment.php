<?php

namespace App\Ai\Tools;

use App\Models\ChatMessage;
use App\Models\File;

/**
 * Finds the most recent file a user attached in a chat session, for tools that
 * act on an uploaded document (import, receipt extraction, attach-to-transaction).
 */
trait ResolvesChatAttachment
{
    protected function latestAttachment(int $sessionId): ?File
    {
        $message = ChatMessage::query()
            ->where('chat_session_id', $sessionId)
            ->whereHas('files')
            ->latest('id')
            ->first();

        return $message?->files()->latest('id')->first();
    }
}
