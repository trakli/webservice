<?php

namespace App\Listeners;

use App\Enums\ReminderStatus;
use App\Enums\ReminderType;
use App\Events\BudgetForecastBreached;
use App\Events\BudgetThresholdBreached;
use App\Models\Budget;
use App\Models\Reminder;

class CreateBudgetAlertReminder
{
    public function handleThreshold(BudgetThresholdBreached $event): void
    {
        $this->createReminder(
            budgetId: $event->budgetId,
            periodStart: $event->periodStart,
            source: 'threshold',
            titleKey: 'Budget near limit',
            bodyKey: 'You have used {percent}% of your {name} budget',
            bodyContext: ['percent' => (int) round($event->percentUsed)],
        );
    }

    public function handleForecast(BudgetForecastBreached $event): void
    {
        $this->createReminder(
            budgetId: $event->budgetId,
            periodStart: $event->periodStart,
            source: 'forecast',
            titleKey: 'Budget forecast to breach',
            bodyKey: 'At this pace, your {name} budget will exceed its limit this period',
            bodyContext: [],
        );
    }

    private function createReminder(
        int $budgetId,
        string $periodStart,
        string $source,
        string $titleKey,
        string $bodyKey,
        array $bodyContext,
    ): void {
        $budget = Budget::query()->with('owner')->find($budgetId);
        if (! $budget) {
            return;
        }

        $owner = $budget->owner;
        if (! $owner || ! method_exists($owner, 'reminders')) {
            return;
        }

        // Idempotent per (budget, source, period) — a new period naturally
        // re-opens eligibility since we key off the period_start date.
        $exists = Reminder::query()
            ->where('remindable_type', Budget::class)
            ->where('remindable_id', $budgetId)
            ->where('source', $source)
            ->whereDate('created_at', '>=', $periodStart)
            ->exists();

        if ($exists) {
            return;
        }

        $body = __($bodyKey, array_merge(['name' => $budget->name], $bodyContext));

        /** @var Reminder $reminder */
        $reminder = $owner->reminders()->create([
            'title' => __($titleKey),
            'description' => $body,
            'type' => ReminderType::BUDGET_ALERT,
            'source' => $source,
            'remindable_type' => Budget::class,
            'remindable_id' => $budgetId,
            'trigger_at' => now(),
            'timezone' => 'UTC',
            'status' => ReminderStatus::ACTIVE,
            'priority' => $source === 'threshold' ? 2 : 1,
        ]);

        $reminder->calculateNextTrigger();
        $reminder->save();
    }
}
