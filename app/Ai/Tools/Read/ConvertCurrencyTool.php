<?php

namespace App\Ai\Tools\Read;

use App\Models\User;
use App\Services\ExchangeRateService;
use Whilesmart\Agents\Enums\ToolPermission;
use Whilesmart\Agents\Tools\AbstractTool;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Converts an amount between currencies at the same rates the rest of Trakli
 * uses, honouring the user's manual overrides. Doing the arithmetic here rather
 * than leaving the model to multiply keeps a converted figure consistent with
 * what budgets and stats report.
 */
class ConvertCurrencyTool extends AbstractTool
{
    public function __construct(private readonly ExchangeRateService $exchangeRates)
    {
    }

    public function name(): string
    {
        return 'convert_currency';
    }

    public function description(): string
    {
        return 'Convert an amount from one currency to another at the rate Trakli uses, '
            . "honouring the user's own manual rates. Use this whenever an amount must be "
            . 'shown in a different currency than it was recorded in, instead of applying a '
            . 'rate yourself. Never estimate a rate.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::number('amount', 'The amount to convert.'),
            ParameterSpec::string('from', 'The currency code the amount is currently in.'),
            ParameterSpec::string('to', 'The currency code to convert into.'),
        ];
    }

    public function permission(): ToolPermission
    {
        return ToolPermission::READ;
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $user = $context->user;

        if (! $user instanceof User) {
            return ['error' => 'No authenticated user in context.'];
        }

        $source = strtoupper(trim((string) ($arguments['from'] ?? '')));
        $target = strtoupper(trim((string) ($arguments['to'] ?? '')));

        if ($source === '' || $target === '') {
            return ['error' => 'Both a source and a target currency are required.'];
        }

        if (! is_numeric($arguments['amount'] ?? null)) {
            return ['error' => 'A numeric amount is required.'];
        }

        $amount = (float) $arguments['amount'];
        $converted = $this->exchangeRates->convert($amount, $source, $target, $user);

        if ($converted === null) {
            return ['error' => "No exchange rate is available from {$source} to {$target}. "
                . 'Tell the user rather than estimating one; they can set a manual rate in settings.'];
        }

        $rate = $this->exchangeRates->getRate($source, $target, $user);

        return [
            'amount' => $amount,
            'from' => $source,
            'to' => $target,
            'rate' => $rate,
            'converted' => round((float) $converted, 2),
        ];
    }
}
