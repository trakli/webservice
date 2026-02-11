<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Mail\InactivityReminderMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Whilesmart\ModelConfiguration\Enums\ConfigValueType;

class InactivityService
{
    public const CONFIG_LAST_REMINDER_SENT = 'last-inactivity-reminder-sent';

    public const CONFIG_INACTIVITY_REMINDERS_ENABLED = 'inactivity-reminders-enabled';

    public const CONFIG_INACTIVITY_REMINDER_COUNT = 'inactivity-reminder-count';

    public const MAX_REMINDERS = 4;

    public const MAX_INACTIVITY_DAYS = 60;

    public const INACTIVITY_TIERS = [
        7 => [
            'subject' => "It's been a week!",
            'message' => "You haven't tracked any transactions in a week. Did you really not spend anything? 🤔",
            // @phpcs:ignore
            'encouragement' => "We know tracking every expense isn't easy - but if you really want to take control of your money, consistency is key. Even logging a few transactions helps build the habit.",
            'cta' => 'Log a transaction now to keep your finances on track.',
        ],
        14 => [
            'subject' => 'We miss you!',
            'message' => 'Two weeks without tracking - your budget misses you! Even small purchases add up.',
            // @phpcs:ignore
            'encouragement' => "Building financial awareness is hard, and life gets busy. But here's the thing: the people who succeed at managing money aren't perfect - they just keep trying. Every transaction you log is a step forward.",
            'cta' => 'Take 30 seconds to log what you remember.',
        ],
        30 => [
            'subject' => 'A month already?',
            'message' => "It's been a whole month since you last tracked your spending. Ready to get back on track?",
            // @phpcs:ignore
            'encouragement' => "We get it - tracking finances can feel like a chore. But financial freedom doesn't come from being perfect, it comes from showing up. You've already taken the hardest step by signing up. Don't let that effort go to waste.",
            'cta' => 'Start fresh today. No judgment, just a clean slate.',
        ],
    ];

    public const MIN_DAYS_BETWEEN_REMINDERS = 7;

    public function __construct(
        protected NotificationService $notificationService
    ) {
    }

    public function sendInactivityReminders(): int
    {
        $sent = 0;

        $inactiveUsers = $this->getInactiveUsers();

        foreach ($inactiveUsers as $userData) {
            $user = $userData['user'];
            $daysSinceLastTransaction = $userData['days_inactive'];
            $tier = $this->getTierForDays($daysSinceLastTransaction);

            if (! $tier) {
                continue;
            }

            if (! $this->shouldSendReminder($user, $daysSinceLastTransaction)) {
                continue;
            }

            try {
                $this->sendReminderNotification($user, $tier, $daysSinceLastTransaction);
                $this->markReminderSent($user);
                $sent++;
            } catch (\Throwable $e) {
                Log::error('Failed to send inactivity reminder', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    protected function getInactiveUsers(): array
    {
        $minInactivityDays = min(array_keys(self::INACTIVITY_TIERS));
        $cutoffDate = Carbon::now()->subDays($minInactivityDays);

        $users = User::query()
            ->whereDoesntHave('transactions', function ($query) use ($cutoffDate) {
                $query->where('created_at', '>=', $cutoffDate);
            })
            ->whereHas('transactions')
            ->get();

        $result = [];

        foreach ($users as $user) {
            $lastTransaction = $user->transactions()
                ->orderBy('created_at', 'desc')
                ->first();

            if ($lastTransaction) {
                $daysSince = Carbon::parse($lastTransaction->created_at)->diffInDays(now());
                $result[] = [
                    'user' => $user,
                    'days_inactive' => $daysSince,
                    'last_transaction_at' => $lastTransaction->created_at,
                ];
            }
        }

        return $result;
    }

    protected function getTierForDays(int $days): ?array
    {
        $applicableTier = null;

        foreach (self::INACTIVITY_TIERS as $tierDays => $tier) {
            if ($days >= $tierDays) {
                $applicableTier = $tier;
            }
        }

        return $applicableTier;
    }

    protected function shouldSendReminder(User $user, int $daysInactive): bool
    {
        if ($daysInactive > self::MAX_INACTIVITY_DAYS) {
            return false;
        }

        $enabled = $user->getConfigValue(self::CONFIG_INACTIVITY_REMINDERS_ENABLED);
        if ($enabled === 'false' || $enabled === false) {
            return false;
        }

        $reminderCount = (int) $user->getConfigValue(self::CONFIG_INACTIVITY_REMINDER_COUNT, 0);
        if ($reminderCount >= self::MAX_REMINDERS) {
            return false;
        }

        $lastSent = $user->getConfigValue(self::CONFIG_LAST_REMINDER_SENT);
        if ($lastSent) {
            $daysSinceLastReminder = Carbon::parse($lastSent)->diffInDays(now());
            if ($daysSinceLastReminder < self::MIN_DAYS_BETWEEN_REMINDERS) {
                return false;
            }
        }

        return true;
    }

    protected function sendReminderNotification(User $user, array $tier, int $daysInactive): void
    {
        $this->notificationService->send(
            user: $user,
            type: NotificationType::ALERT,
            title: $tier['subject'],
            body: $tier['message'] . ' ' . $tier['encouragement'],
            data: [
                'type' => 'inactivity',
                'days_inactive' => $daysInactive,
                'cta' => $tier['cta'],
            ],
            mailable: new InactivityReminderMail($user, $tier, $daysInactive),
            channels: ['inapp', 'email', 'push']
        );

        Log::info('Inactivity reminder sent', [
            'user_id' => $user->id,
            'days_inactive' => $daysInactive,
        ]);
    }

    protected function markReminderSent(User $user): void
    {
        $user->setConfigValue(self::CONFIG_LAST_REMINDER_SENT, now()->toIso8601String(), ConfigValueType::String);

        $count = (int) $user->getConfigValue(self::CONFIG_INACTIVITY_REMINDER_COUNT, 0);
        $user->setConfigValue(self::CONFIG_INACTIVITY_REMINDER_COUNT, $count + 1, ConfigValueType::Integer);
    }

    public function resetReminderCount(User $user): void
    {
        $user->setConfigValue(self::CONFIG_INACTIVITY_REMINDER_COUNT, 0, ConfigValueType::Integer);
        $user->setConfigValue(self::CONFIG_LAST_REMINDER_SENT, '', ConfigValueType::String);
    }
}
