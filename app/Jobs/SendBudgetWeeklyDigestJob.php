<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Models\User;
use App\Services\BudgetProgressService;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendBudgetWeeklyDigestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $userId,
    ) {
    }

    public function handle(BudgetProgressService $progressService, NotificationService $notifications): void
    {
        $user = User::query()->find($this->userId);
        if (! $user) {
            return;
        }

        if ($user->getConfigValue('budget-weekly-digest-enabled') === false) {
            return;
        }

        $workspaceIds = $user->workspaces()->where('is_active', true)->pluck('workspaces.id');
        $budgets = \App\Models\Budget::where('is_active', true)->where(function ($query) use ($user, $workspaceIds) {
            $query->where(fn ($personal) => $personal->where('owner_type', User::class)->where('owner_id', $user->id))
                ->orWhere(fn ($shared) => $shared->where('owner_type', \App\Models\Workspace::class)->whereIn('owner_id', $workspaceIds));
        })->get();
        if ($budgets->isEmpty()) {
            return;
        }

        $breaching = 0;
        $onTrack = 0;

        foreach ($budgets as $budget) {
            $progress = $progressService->compute($budget);
            if ($progress['status'] === BudgetProgressService::STATUS_ON_TRACK) {
                $onTrack++;
            } else {
                $breaching++;
            }
        }

        $notifications->send(
            user: $user,
            type: NotificationType::REMINDER,
            title: __('Your weekly budget digest'),
            body: __(':breaching budget(s) need attention, :onTrack on track.', [
                'breaching' => $breaching,
                'onTrack' => $onTrack,
            ]),
            data: ['kind' => 'budget_digest'],
            channels: ['inapp', 'push', 'email']
        );
    }
}
