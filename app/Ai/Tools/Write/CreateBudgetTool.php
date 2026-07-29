<?php

namespace App\Ai\Tools\Write;

use App\Models\Budget;
use App\Services\BudgetTargetResolver;
use App\Support\ConfigurationKeys;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Whilesmart\Agents\Enums\ParameterType;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Proposes a spending limit for a period, optionally scoped to some of the
 * user's categories, groups or wallets.
 *
 * Targets are what make this more than a column write: they land on a
 * polymorphic pivot and each one is resolved against the user's own records
 * before anything is proposed.
 */
class CreateBudgetTool extends AbstractWriteTool
{
    public function __construct(private BudgetTargetResolver $targets)
    {
    }

    public function name(): string
    {
        return 'create_budget';
    }

    public function actionType(): string
    {
        return 'budget.create';
    }

    public function description(): string
    {
        return 'Propose creating a budget: a spending limit for a repeating period. Needs a name, an '
            . 'amount, a currency and a period (weekly, monthly, yearly or custom). Optionally limit it '
            . 'to particular categories, groups or wallets via targets. The user confirms before it is saved.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('name', 'What the budget is called, e.g. "Groceries".'),
            ParameterSpec::number('amount', 'The spending limit for one period, a positive number.'),
            ParameterSpec::string(
                'currency',
                "ISO 4217 currency code, 3 letters. Default to the user's own currency when they do not name one.",
                required: false,
            ),
            ParameterSpec::enum('period_type', 'How often the budget resets.', Budget::PERIODS),
            ParameterSpec::string('start_date', 'First day the budget applies, ISO 8601. Defaults to today.', required: false),
            ParameterSpec::string('end_date', 'Last day the budget applies, ISO 8601. Required for a custom period.', required: false),
            ParameterSpec::string('description', 'What the budget covers.', required: false),
            ParameterSpec::number('threshold_percent', 'Percentage used at which the user is warned. Defaults to 80.', required: false),
            ParameterSpec::boolean('rollover_enabled', 'Whether unspent money carries into the next period.', required: false),
            ParameterSpec::arrayOfObject(
                'targets',
                'Limit the budget to specific records. One entry per target; omit to budget across everything.',
                [
                    new ParameterSpec('type', ParameterType::ENUM, 'What kind of record this target is.', true, ['category', 'group', 'wallet']),
                    ParameterSpec::number('id', 'The record id, from list_categories or list_wallets.', required: false),
                    ParameterSpec::string('name', 'The exact record name, if you do not have its id.', required: false),
                ],
                required: false,
            ),
        ];
    }

    protected function buildPayload(array $arguments, ToolContext $context): array
    {
        $user = $context->user;

        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('A budget name is required.');
        }

        $periodType = $this->resolvePeriodType($arguments);
        [$startDate, $endDate] = $this->resolveDates($arguments, $periodType);

        return array_filter([
            'name' => $name,
            'amount' => $this->resolveAmount($arguments),
            'currency' => $this->resolveCurrency($arguments, $user),
            'period_type' => $periodType,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'description' => $arguments['description'] ?? null,
            'threshold_percent' => isset($arguments['threshold_percent']) ? (int) $arguments['threshold_percent'] : null,
            'rollover_enabled' => $arguments['rollover_enabled'] ?? null,
            'targets' => $this->resolveTargets($arguments, $user),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveAmount(array $arguments): float
    {
        $amount = (float) ($arguments['amount'] ?? 0);

        if ($amount <= 0) {
            throw new InvalidArgumentException('The budget amount must be greater than zero.');
        }

        return $amount;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolvePeriodType(array $arguments): string
    {
        $periodType = $arguments['period_type'] ?? null;

        if (! in_array($periodType, Budget::PERIODS, true)) {
            throw new InvalidArgumentException('The period must be one of ' . implode(', ', Budget::PERIODS) . '.');
        }

        return $periodType;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveCurrency(array $arguments, $user): string
    {
        $currency = strtoupper(trim((string) ($arguments['currency'] ?? '')));

        if ($currency === '') {
            $currency = strtoupper((string) $this->defaultCurrency($user));
        }

        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException(
                'Currency must be a 3-letter ISO 4217 code. Ask the user which currency the budget is in.'
            );
        }

        return $currency;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: string, 1: string|null}
     */
    private function resolveDates(array $arguments, string $periodType): array
    {
        $startDate = $this->parseDate($arguments['start_date'] ?? null) ?? Carbon::today()->toDateString();
        $endDate = $this->parseDate($arguments['end_date'] ?? null);

        if ($periodType === Budget::PERIOD_CUSTOM && $endDate === null) {
            throw new InvalidArgumentException('A custom period needs an end date.');
        }

        if ($endDate !== null && $endDate < $startDate) {
            throw new InvalidArgumentException('The end date cannot be before the start date.');
        }

        return [$startDate, $endDate];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<int, array{type: string, id: int}>|null
     */
    private function resolveTargets(array $arguments, $user): ?array
    {
        $targets = $this->targets->resolve($user, $arguments['targets'] ?? []);

        return $targets === [] ? null : $targets;
    }

    private function defaultCurrency($user): ?string
    {
        $currency = $user->getConfigValue(ConfigurationKeys::DEFAULT_CURRENCY);

        if ($currency) {
            return $currency;
        }

        $currencies = $user->wallets()->pluck('currency')->filter()->unique();

        return $currencies->count() === 1 ? $currencies->first() : null;
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            throw new InvalidArgumentException("\"{$value}\" is not a date I can read. Use a plain date like 2026-03-01.");
        }
    }

    protected function summarize(array $payload, ToolContext $context): string
    {
        $summary = "Budget {$payload['amount']} {$payload['currency']} {$payload['period_type']} for \"{$payload['name']}\"";

        $targets = $payload['targets'] ?? [];
        if ($targets !== []) {
            $summary .= ', covering ' . count($targets) . ' ' . (count($targets) === 1 ? 'target' : 'targets');
        }

        return $summary . '.';
    }

    protected function reviewFields(array $payload, ToolContext $context): array
    {
        $fields = [
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => $payload['name'] ?? '', 'display' => (string) ($payload['name'] ?? '')],
            ['key' => 'amount', 'label' => 'Limit', 'type' => 'number', 'value' => $payload['amount'] ?? 0, 'display' => (string) ($payload['amount'] ?? '')],
            [
                'key' => 'currency', 'label' => 'Currency', 'type' => 'text',
                'value' => $payload['currency'] ?? '', 'display' => (string) ($payload['currency'] ?? ''),
            ],
            [
                'key' => 'period_type', 'label' => 'Resets', 'type' => 'text',
                'value' => $payload['period_type'] ?? '', 'display' => (string) ($payload['period_type'] ?? ''),
            ],
            [
                'key' => 'start_date', 'label' => 'Starts', 'type' => 'date',
                'value' => $payload['start_date'] ?? null, 'display' => (string) ($payload['start_date'] ?? ''),
            ],
        ];

        if (! empty($payload['end_date'])) {
            $fields[] = ['key' => 'end_date', 'label' => 'Ends', 'type' => 'date', 'value' => $payload['end_date'], 'display' => (string) $payload['end_date']];
        }

        if (! empty($payload['targets'])) {
            $display = implode(', ', array_map(
                fn (array $target): string => "{$target['type']} #{$target['id']}",
                $payload['targets'],
            ));

            $fields[] = ['key' => 'targets', 'label' => 'Applies to', 'type' => 'list', 'value' => $payload['targets'], 'display' => $display];
        }

        return $fields;
    }
}
