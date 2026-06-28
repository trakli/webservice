<?php

namespace App\Ai\Tools\Read;

use App\Models\User;
use App\Services\ExchangeRateService;
use Whilesmart\Agents\Enums\ParameterType;
use Whilesmart\Agents\Enums\ToolPermission;
use Whilesmart\Agents\Tools\AbstractTool;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Returns current exchange rates from a base currency, honoring the user's
 * manual rates. Use it to ground any cross-currency figure before answering
 * so the assistant matches what budgets and stats compute.
 */
class GetExchangeRateTool extends AbstractTool
{
    public function __construct(private ExchangeRateService $exchangeRates)
    {
    }

    public function name(): string
    {
        return 'get_exchange_rate';
    }

    public function description(): string
    {
        return 'Get current exchange rates from a base currency to one or more targets. '
            . 'Honors the user\'s manual exchange rates, so the numbers match budgets and '
            . 'stats. Returns authoritative rates; never invent or estimate a rate.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('base', 'Base currency code, e.g. "USD".'),
            ParameterSpec::arrayOf('targets', 'Target currency codes to convert into, e.g. ["EUR", "GBP"].', ParameterType::STRING),
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

        $base = strtoupper((string) ($arguments['base'] ?? ''));
        if ($base === '') {
            return ['error' => 'A base currency is required.'];
        }

        $targets = collect($arguments['targets'] ?? [])
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values();

        if ($targets->isEmpty()) {
            return ['error' => 'At least one target currency is required.'];
        }

        $rates = [];
        $unavailable = [];

        foreach ($targets as $target) {
            $rate = $this->exchangeRates->getRate($base, $target, $user);

            if ($rate === null) {
                $unavailable[] = $target;

                continue;
            }

            $rates[$target] = $rate;
        }

        return [
            'base' => $base,
            'rates' => $rates,
            'unavailable' => $unavailable,
        ];
    }
}
