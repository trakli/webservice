<?php

namespace App\Ai\Tools\Write;

use InvalidArgumentException;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Proposes creating a wallet.
 */
class CreateWalletTool extends AbstractWriteTool
{
    public function name(): string
    {
        return 'create_wallet';
    }

    public function actionType(): string
    {
        return 'wallet.create';
    }

    public function description(): string
    {
        return 'Propose creating a wallet. Needs a name, a type (bank, cash, credit_card, mobile) '
            . 'and a 3-letter currency code. The user confirms before it is created.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('name', 'The wallet name, e.g. "Cash".'),
            ParameterSpec::enum('type', 'The wallet type.', ['bank', 'cash', 'credit_card', 'mobile']),
            ParameterSpec::string('currency', "ISO 4217 currency code, 3 letters. Default to the user's own currency when they do not name one."),
            ParameterSpec::string('description', 'Optional description.', required: false),
        ];
    }

    protected function buildPayload(array $arguments, ToolContext $context): array
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('A wallet name is required.');
        }

        $type = $arguments['type'] ?? null;
        if (! in_array($type, ['bank', 'cash', 'credit_card', 'mobile'], true)) {
            throw new InvalidArgumentException('Type must be one of bank, cash, credit_card, mobile.');
        }

        $currency = strtoupper(trim((string) ($arguments['currency'] ?? '')));
        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException('Currency must be a 3-letter ISO 4217 code.');
        }

        return array_filter([
            'name' => $name,
            'type' => $type,
            'currency' => $currency,
            'description' => $arguments['description'] ?? null,
        ], fn ($value) => $value !== null);
    }

    protected function summarize(array $payload, ToolContext $context): string
    {
        return "Create a {$payload['type']} wallet \"{$payload['name']}\" in {$payload['currency']}.";
    }
}
