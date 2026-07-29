<?php

namespace App\Ai\Tools\Write;

use App\Models\RecurringTransactionRule;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Proposes turning an existing transaction into a repeating one (rent, salary,
 * a subscription).
 *
 * A rule has no owner column of its own: it is owned through the transaction it
 * repeats, so that transaction is checked against the acting user here rather
 * than trusted from the model.
 */
class CreateRecurringRuleTool extends AbstractWriteTool
{
    public function name(): string
    {
        return 'create_recurring_rule';
    }

    public function actionType(): string
    {
        return 'recurring_rule.create';
    }

    public function description(): string
    {
        return 'Propose repeating an existing transaction on a schedule. Give the id of the transaction '
            . '(find it with smartql.query) and how often it repeats: daily, weekly, monthly or yearly, '
            . 'optionally every N of those. The user confirms before it is saved.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::number('transaction_id', 'The id of the transaction to repeat.'),
            ParameterSpec::enum('recurrence_period', 'The unit it repeats on.', RecurringTransactionRule::RECURRENCE_PERIODS),
            ParameterSpec::number('recurrence_interval', 'How many of those units between occurrences. Defaults to 1.', required: false),
            ParameterSpec::string(
                'next_scheduled_at',
                'When the next occurrence is due, ISO 8601. Defaults to one interval after the transaction.',
                required: false,
            ),
            ParameterSpec::string('recurrence_ends_at', 'When it should stop repeating, ISO 8601. Leave empty to repeat indefinitely.', required: false),
        ];
    }

    protected function buildPayload(array $arguments, ToolContext $context): array
    {
        $user = $context->user;

        $transactionId = (int) ($arguments['transaction_id'] ?? 0);
        $transaction = $user->transactions()->find($transactionId);

        if ($transaction === null) {
            throw new InvalidArgumentException('That transaction was not found. Find the transaction first, then pass its id.');
        }

        if ($transaction->recurringTransactionRule()->exists()) {
            throw new InvalidArgumentException('That transaction already repeats. Tell the user it is already set up.');
        }

        $period = $arguments['recurrence_period'] ?? null;
        if (! in_array($period, RecurringTransactionRule::RECURRENCE_PERIODS, true)) {
            throw new InvalidArgumentException('The recurrence must be one of ' . implode(', ', RecurringTransactionRule::RECURRENCE_PERIODS) . '.');
        }

        $interval = isset($arguments['recurrence_interval']) ? (int) $arguments['recurrence_interval'] : 1;
        if ($interval < 1) {
            throw new InvalidArgumentException('The interval must be at least 1.');
        }

        $next = $this->parseDateTime($arguments['next_scheduled_at'] ?? null)
            ?? $this->nextAfter($transaction->datetime ?? now(), $period, $interval);

        $endsAt = $this->parseDateTime($arguments['recurrence_ends_at'] ?? null);

        if ($endsAt !== null && $endsAt <= $next) {
            throw new InvalidArgumentException('The end date must be after the next occurrence.');
        }

        return array_filter([
            'transaction_id' => $transactionId,
            'recurrence_period' => $period,
            'recurrence_interval' => $interval,
            'next_scheduled_at' => $next->toIso8601String(),
            'recurrence_ends_at' => $endsAt?->toIso8601String(),
        ], fn ($value) => $value !== null);
    }

    private function nextAfter(mixed $from, string $period, int $interval): Carbon
    {
        $start = Carbon::parse((string) $from);

        return match ($period) {
            'daily' => $start->addDays($interval),
            'weekly' => $start->addWeeks($interval),
            'yearly' => $start->addYears($interval),
            default => $start->addMonths($interval),
        };
    }

    private function parseDateTime(mixed $value): ?Carbon
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw new InvalidArgumentException("\"{$value}\" is not a date I can read.");
        }
    }

    protected function summarize(array $payload, ToolContext $context): string
    {
        $transaction = $context->user->transactions()->find($payload['transaction_id']);
        $what = $transaction?->description ?: "transaction #{$payload['transaction_id']}";
        $interval = (int) ($payload['recurrence_interval'] ?? 1);
        $every = $interval === 1
            ? $this->adverb($payload['recurrence_period'])
            : "every {$interval} " . $payload['recurrence_period'] . 's';

        return "Repeat \"{$what}\" {$every}.";
    }

    private function adverb(string $period): string
    {
        return match ($period) {
            'daily' => 'daily',
            'weekly' => 'weekly',
            'yearly' => 'yearly',
            default => 'monthly',
        };
    }

    protected function reviewFields(array $payload, ToolContext $context): array
    {
        $transaction = $context->user->transactions()->find($payload['transaction_id'] ?? null);

        return [
            [
                'key' => 'transaction_id', 'label' => 'Transaction', 'type' => 'transaction',
                'value' => $payload['transaction_id'] ?? null,
                'display' => (string) ($transaction?->description ?? ('#' . ($payload['transaction_id'] ?? ''))),
            ],
            [
                'key' => 'recurrence_period', 'label' => 'Repeats', 'type' => 'text',
                'value' => $payload['recurrence_period'] ?? '', 'display' => (string) ($payload['recurrence_period'] ?? ''),
            ],
            [
                'key' => 'recurrence_interval', 'label' => 'Every', 'type' => 'number',
                'value' => $payload['recurrence_interval'] ?? 1, 'display' => (string) ($payload['recurrence_interval'] ?? 1),
            ],
            [
                'key' => 'next_scheduled_at', 'label' => 'Next due', 'type' => 'datetime',
                'value' => $payload['next_scheduled_at'] ?? null, 'display' => (string) ($payload['next_scheduled_at'] ?? ''),
            ],
            [
                'key' => 'recurrence_ends_at', 'label' => 'Until', 'type' => 'datetime',
                'value' => $payload['recurrence_ends_at'] ?? null, 'display' => (string) ($payload['recurrence_ends_at'] ?? 'No end date'),
            ],
        ];
    }
}
