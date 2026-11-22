<?php

namespace App\Listeners;

use App\Enums\StreakType;
use App\Events\TransactionRecorded;
use App\Models\User;
use App\Services\StreakService;
use Carbon\CarbonImmutable;

class AdvanceTransactionStreak
{
    public function __construct(
        private readonly StreakService $streaks,
    ) {
    }

    public function handle(TransactionRecorded $event): void
    {
        if ($event->action !== 'created') {
            return;
        }

        $owner = User::find($event->userId);

        if ($owner === null) {
            return;
        }

        $this->streaks->track($owner, StreakType::TRANSACTION, CarbonImmutable::now());
    }
}
