<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\TransactionType;
use App\Mail\InsightsMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class InsightsService
{
    public const CONFIG_KEY = 'insights-frequency';

    public function __construct(
        protected NotificationService $notificationService
    ) {
    }

    public function sendInsights(string $frequency = 'weekly'): int
    {
        $sent = 0;

        $users = $this->getUsersWithFrequency($frequency);

        foreach ($users as $user) {
            try {
                $insights = $this->generateInsights($user, $frequency);
                $this->sendInsightsNotification($user, $insights, $frequency);
                $sent++;
            } catch (\Throwable $e) {
                Log::error('Failed to send insights', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    protected function getUsersWithFrequency(string $frequency): \Illuminate\Support\Collection
    {
        return User::whereHas('configurations', function ($query) use ($frequency) {
            $query->where('key', self::CONFIG_KEY)
                ->where('value', $frequency);
        })->get();
    }

    public function generateInsights(User $user, string $frequency = 'weekly'): array
    {
        $period = $this->getPeriodDates($frequency);

        $transactions = $user->transactions()
            ->whereBetween('datetime', [$period['start'], $period['end']])
            ->get();

        $income = $transactions->where('type', TransactionType::INCOME)->sum('amount');
        $expenses = $transactions->where('type', TransactionType::EXPENSE)->sum('amount');

        $expensesByCategory = $transactions
            ->where('type', TransactionType::EXPENSE)
            ->groupBy(fn ($t) => $t->categories->first()?->name ?? 'Uncategorized')
            ->map(fn ($group) => $group->sum('amount'))
            ->sortDesc()
            ->take(5);

        $topExpense = $transactions
            ->where('type', TransactionType::EXPENSE)
            ->sortByDesc('amount')
            ->first();

        $transactionCount = $transactions->count();
        $savingsRate = $income > 0 ? round((($income - $expenses) / $income) * 100, 1) : 0;

        $previousPeriod = $this->getPreviousPeriodDates($frequency);
        $previousExpenses = $user->transactions()
            ->where('type', TransactionType::EXPENSE)
            ->whereBetween('datetime', [$previousPeriod['start'], $previousPeriod['end']])
            ->sum('amount');

        $expenseChange = $previousExpenses > 0
            ? round((($expenses - $previousExpenses) / $previousExpenses) * 100, 1)
            : 0;

        return [
            'period' => $period,
            'frequency' => $frequency,
            'income' => $income,
            'expenses' => $expenses,
            'net' => $income - $expenses,
            'savings_rate' => $savingsRate,
            'transaction_count' => $transactionCount,
            'expenses_by_category' => $expensesByCategory->toArray(),
            'top_expense' => $topExpense ? [
                'description' => $topExpense->description,
                'amount' => $topExpense->amount,
                'category' => $topExpense->categories->first()?->name,
            ] : null,
            'expense_change_percent' => $expenseChange,
        ];
    }

    protected function getPeriodDates(string $frequency): array
    {
        if ($frequency === 'monthly') {
            return [
                'start' => Carbon::now()->subMonth()->startOfMonth(),
                'end' => Carbon::now()->subMonth()->endOfMonth(),
                'label' => Carbon::now()->subMonth()->format('F Y'),
            ];
        }

        return [
            'start' => Carbon::now()->subWeek()->startOfWeek(),
            'end' => Carbon::now()->subWeek()->endOfWeek(),
            'label' => Carbon::now()->subWeek()->startOfWeek()->format('M j') . ' - ' .
                       Carbon::now()->subWeek()->endOfWeek()->format('M j, Y'),
        ];
    }

    protected function getPreviousPeriodDates(string $frequency): array
    {
        if ($frequency === 'monthly') {
            return [
                'start' => Carbon::now()->subMonths(2)->startOfMonth(),
                'end' => Carbon::now()->subMonths(2)->endOfMonth(),
            ];
        }

        return [
            'start' => Carbon::now()->subWeeks(2)->startOfWeek(),
            'end' => Carbon::now()->subWeeks(2)->endOfWeek(),
        ];
    }

    protected function sendInsightsNotification(User $user, array $insights, string $frequency): void
    {
        $periodLabel = $frequency === 'monthly' ? 'Monthly' : 'Weekly';
        $title = "{$periodLabel} Financial Insights";
        $body = "Your {$periodLabel} insights are ready. " .
                "Income: \${$insights['income']}, Expenses: \${$insights['expenses']}, " .
                "Savings rate: {$insights['savings_rate']}%";

        $this->notificationService->send(
            user: $user,
            type: NotificationType::SYSTEM,
            title: $title,
            body: $body,
            data: [
                'type' => 'insights',
                'frequency' => $frequency,
                'period_label' => $insights['period']['label'],
            ],
            mailable: new InsightsMail($user, $insights, $periodLabel),
            channels: ['inapp', 'email']
        );

        Log::info('Insights notification sent', [
            'user_id' => $user->id,
            'frequency' => $frequency,
        ]);
    }
}
