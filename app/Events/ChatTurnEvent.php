<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A live update for one chat turn, pushed to the session's private channel as
 * the assistant works. `progress` carries a step label as each tool runs;
 * `settled` fires once the answer is saved so the client swaps the progress
 * view for the result without waiting on a poll.
 *
 * Broadcast now (not queued): the emitting job already holds the worker, so a
 * queued broadcast would not leave the queue until the turn finished, defeating
 * the point.
 */
class ChatTurnEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $chatSessionId,
        public int $messageId,
        public string $kind,
        public ?string $label = null,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat-session.{$this->chatSessionId}")];
    }

    public function broadcastAs(): string
    {
        return 'turn';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'kind' => $this->kind,
            'label' => $this->label,
        ];
    }
}
