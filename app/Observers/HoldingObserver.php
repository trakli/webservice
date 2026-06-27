<?php

namespace App\Observers;

use App\Models\User;
use App\Services\StatsService;
use Whilesmart\Holdings\Models\Holding;

class HoldingObserver
{
    public function created(Holding $holding): void
    {
        $this->invalidate($holding);
    }

    public function updated(Holding $holding): void
    {
        $this->invalidate($holding);
    }

    public function deleted(Holding $holding): void
    {
        $this->invalidate($holding);
    }

    protected function invalidate(Holding $holding): void
    {
        if ($holding->owner_type === User::class && $holding->owner_id) {
            StatsService::invalidateUserCache((int) $holding->owner_id);
        }
    }
}
