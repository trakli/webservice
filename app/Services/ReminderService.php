<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ReminderService
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    public function processDueReminders(): int
    {
        $processed = 0;

        $reminders = Reminder::query()
            ->where('status', ReminderStatus::ACTIVE)
            ->whereNotNull('next_trigger_at')
            ->where('next_trigger_at', '<=', now())
            ->with('user')
            ->get();

        foreach ($reminders as $reminder) {
            try {
                $this->processReminder($reminder);
                $processed++;
            } catch (\Throwable $e) {
                Log::error('Failed to process reminder', [
                    'reminder_id' => $reminder->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $processed;
    }

    protected function processReminder(Reminder $reminder): void
    {
        $user = $reminder->user;

        $results = $this->notificationService->send(
            user: $user,
            type: NotificationType::REMINDER,
            title: $reminder->title,
            body: $reminder->description ?? '',
            data: [
                'reminder_id' => $reminder->id,
                'reminder_type' => $reminder->type->value,
            ],
            channels: ['inapp', 'push']
        );

        $reminder->last_triggered_at = now();
        $reminder->calculateNextTrigger();

        if (! $reminder->isRecurring() || ! $reminder->next_trigger_at) {
            $reminder->status = ReminderStatus::COMPLETED;
        }

        $reminder->save();

        Log::info('Reminder processed', [
            'reminder_id' => $reminder->id,
            'user_id' => $user->id,
            'notification_created' => $results['inapp'] !== null,
            'push_sent' => $results['push'],
        ]);
    }

    public function createReminder(User $user, array $data): Reminder
    {
        $reminder = $user->reminders()->create($data);
        $reminder->calculateNextTrigger();
        $reminder->save();

        return $reminder;
    }
}
