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
            [
                'subject' => 'Your tracking went quiet',
                // @phpcs:ignore
                'message' => 'It has been a week since your last entry. A few minutes now beats a backlog later.',
                // @phpcs:ignore
                'encouragement' => 'If you have records anywhere (bank statements, mobile money exports, screenshots, even photos of receipts) the importer reads each one and lets you confirm before anything is saved. Anything left over, log by hand.',
                'cta' => 'Open the importer',
            ],
            [
                'subject' => 'One week, no tracking',
                // @phpcs:ignore
                'message' => 'Your last transaction was 7 days ago. Whether you bank, use mobile money, or pay in cash, getting back on track only takes a few minutes.',
                // @phpcs:ignore
                'encouragement' => "Drop in a CSV, PDF, or a photo of a receipt and the importer will detect duplicates, suggest categories, and let you confirm. For everything else, a quick manual log keeps you covered.",
                'cta' => 'Catch up now',
            ],
            [
                'subject' => 'Quick check-in',
                // @phpcs:ignore
                'message' => 'It has been a week since you logged a transaction. Most people skip tracking because typing each entry is tedious.',
                // @phpcs:ignore
                'encouragement' => 'The importer takes statements from banks, mobile money services, e-wallets, even photos of receipts, and turns them into a confirmable list. Anything you only remember offhand, log by hand in a few seconds.',
                'cta' => 'Try the importer',
            ],
        ],
        14 => [
            [
                'subject' => 'Two weeks off the books',
                // @phpcs:ignore
                'message' => 'It has been 14 days since your last logged transaction. The sooner you catch up, the less guesswork later.',
                // @phpcs:ignore
                'encouragement' => 'Most of those transactions are sitting somewhere already: a bank or wallet statement, a screenshot, a paper receipt you snapped a photo of. Run them through the importer; the rest, add manually.',
                'cta' => 'Upload a statement',
            ],
            [
                'subject' => 'A fortnight of mystery spending',
                // @phpcs:ignore
                'message' => "Two weeks of unrecorded spending. We won't lecture you. We'll just point you at the fastest way back.",
                // @phpcs:ignore
                'encouragement' => 'Pull a CSV or PDF from your bank or wallet provider, or photograph a stack of receipts. The importer matches everything against your wallets and categories. Anything purely cash and unrecorded, log it in seconds.',
                'cta' => 'Open the importer',
            ],
            [
                'subject' => "Still here? Let's catch up.",
                // @phpcs:ignore
                'message' => '14 days, no entries. Manual logging gets old fast; many active users now upload statements or photographed receipts when they can.',
                // @phpcs:ignore
                'encouragement' => 'Upload one file from your bank or wallet provider, or a few photos of receipts, and most of your last two weeks is reconstructed. Anything cash and undocumented, log by hand.',
                'cta' => 'Try imports',
            ],
        ],
        30 => [
            [
                'subject' => 'A month of untracked finances',
                // @phpcs:ignore
                'message' => '30 days. Long enough that typing each transaction in feels daunting. There is a faster way.',
                // @phpcs:ignore
                'encouragement' => "Upload your last month's statement from your bank, mobile money, or wallet provider, or photograph the receipts you have lying around. The importer rebuilds your timeline; you confirm. For anything else, log by hand.",
                'cta' => 'Catch up with imports',
            ],
            [
                'subject' => 'Reset, the easy way',
                // @phpcs:ignore
                'message' => "It has been a month since your last entry. No judgment; life is loud. Let's get you caught up without the slog.",
                // @phpcs:ignore
                'encouragement' => 'The importer handles bank statements, mobile money exports, wallet CSVs, and even photos of receipts. You pick what to keep. Cash purchases without a paper trail take a few seconds each by hand.',
                'cta' => 'Open the importer',
            ],
            [
                'subject' => "30 days, but you're not behind",
                // @phpcs:ignore
                'message' => 'A full month off-tracker is fine. The fastest way back depends on where your money moves.',
                // @phpcs:ignore
                'encouragement' => 'If you have any records (bank exports, wallet CSVs, photographed receipts) the importer reads them and produces a confirmable list. Anything cash and unrecorded, log it whenever you remember.',
                'cta' => 'Use the importer',
            ],
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
        $variants = $this->getVariantsForDays($days);
        if ($variants === null) {
            return null;
        }

        return $variants[array_rand($variants)];
    }

    protected function getVariantsForDays(int $days): ?array
    {
        $applicable = null;
        foreach (self::INACTIVITY_TIERS as $tierDays => $variants) {
            if ($days >= $tierDays) {
                $applicable = $variants;
            }
        }

        return $applicable;
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
