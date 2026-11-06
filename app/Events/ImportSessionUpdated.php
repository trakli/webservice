<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ImportSessionUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $sessionId, public int $revision, public array $changedIds, public string $by)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("import-session.{$this->sessionId}")];
    }

    public function broadcastAs(): string
    {
        return 'updated';
    }

    public function broadcastWith(): array
    {
        return ['revision' => $this->revision, 'changed_ids' => $this->changedIds, 'by' => $this->by];
    }

    public static function safely(int $id, int $revision, array $ids, string $by): void
    {
        try {
            self::dispatch($id, $revision, $ids, $by);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
