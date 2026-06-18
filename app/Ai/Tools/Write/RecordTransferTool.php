<?php

namespace App\Ai\Tools\Write;

use App\Services\ExchangeRateService;
use InvalidArgumentException;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Proposes moving money between two of the user's wallets. Resolves both wallets
 * by name, settles the exchange rate (1 for same-currency, otherwise a supplied
 * or looked-up rate), and leaves execution to confirm.
 */
class RecordTransferTool extends AbstractWriteTool
{
    use ResolvesUserResources;

    public function __construct(private ExchangeRateService $exchangeRates)
    {
    }

    public function name(): string
    {
        return 'record_transfer';
    }

    public function actionType(): string
    {
        return 'transfer.create';
    }

    public function description(): string
    {
        return 'Propose transferring money between two of the user\'s wallets. Provide the amount and '
            . 'both wallets by name (or id from list_wallets). For wallets in different currencies, '
            . 'pass an exchange_rate or the user will be asked for one. The user confirms before it is saved.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::number('amount', 'The amount to send from the source wallet, a positive number.'),
            ParameterSpec::number('from_wallet_id', 'The id of the source wallet (preferred; get it from list_wallets).', required: false),
            ParameterSpec::string('from_wallet_name', 'The exact source wallet name, if you do not have its id.', required: false),
            ParameterSpec::number('to_wallet_id', 'The id of the destination wallet (preferred; get it from list_wallets).', required: false),
            ParameterSpec::string('to_wallet_name', 'The exact destination wallet name, if you do not have its id.', required: false),
            ParameterSpec::number(
                'exchange_rate',
                'Units of destination currency per source unit. Required only when the wallets use different currencies.',
                required: false,
            ),
            ParameterSpec::string('datetime', 'When it happened, ISO 8601. Defaults to now.', required: false),
        ];
    }

    protected function buildPayload(array $arguments, ToolContext $context): array
    {
        $user = $context->user;

        $amount = (float) ($arguments['amount'] ?? 0);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        $fromWalletId = $this->resolveWalletId($user, [
            'wallet_id' => $arguments['from_wallet_id'] ?? null,
            'wallet_name' => $arguments['from_wallet_name'] ?? null,
        ]);
        $toWalletId = $this->resolveWalletId($user, [
            'wallet_id' => $arguments['to_wallet_id'] ?? null,
            'wallet_name' => $arguments['to_wallet_name'] ?? null,
        ]);

        if ($fromWalletId === $toWalletId) {
            throw new InvalidArgumentException('The source and destination wallets must be different.');
        }

        $fromWallet = $user->wallets()->find($fromWalletId);
        $toWallet = $user->wallets()->find($toWalletId);

        $exchangeRate = $this->resolveExchangeRate($fromWallet->currency, $toWallet->currency, $arguments, $user);

        return array_filter([
            'amount' => $amount,
            'from_wallet_id' => $fromWalletId,
            'to_wallet_id' => $toWalletId,
            'exchange_rate' => $exchangeRate,
            'datetime' => $arguments['datetime'] ?? null,
        ], fn ($value) => $value !== null);
    }

    private function resolveExchangeRate(string $fromCurrency, string $toCurrency, array $arguments, $user): float
    {
        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }

        if (isset($arguments['exchange_rate']) && (float) $arguments['exchange_rate'] > 0) {
            return (float) $arguments['exchange_rate'];
        }

        $rate = $this->exchangeRates->getRate($fromCurrency, $toCurrency, $user);
        if ($rate === null || $rate <= 0) {
            throw new InvalidArgumentException(
                "These wallets use different currencies ({$fromCurrency} -> {$toCurrency}) and no exchange rate is "
                . 'available. Ask the user for the exchange rate to use.'
            );
        }

        return $rate;
    }

    protected function summarize(array $payload, ToolContext $context): string
    {
        $user = $context->user;
        $fromName = optional($user->wallets()->find($payload['from_wallet_id']))->name ?? ('#' . $payload['from_wallet_id']);
        $toName = optional($user->wallets()->find($payload['to_wallet_id']))->name ?? ('#' . $payload['to_wallet_id']);

        return "Transfer {$payload['amount']} from {$fromName} to {$toName}.";
    }

    protected function reviewFields(array $payload, ToolContext $context): array
    {
        $user = $context->user;
        $fromName = optional($user->wallets()->find($payload['from_wallet_id'] ?? null))->name;
        $toName = optional($user->wallets()->find($payload['to_wallet_id'] ?? null))->name;

        return [
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'number', 'value' => $payload['amount'] ?? 0, 'display' => (string) ($payload['amount'] ?? '')],
            [
                'key' => 'from_wallet_id', 'label' => 'From wallet', 'type' => 'wallet',
                'value' => $payload['from_wallet_id'] ?? null, 'display' => $fromName ?? '',
            ],
            [
                'key' => 'to_wallet_id', 'label' => 'To wallet', 'type' => 'wallet',
                'value' => $payload['to_wallet_id'] ?? null, 'display' => $toName ?? '',
            ],
            [
                'key' => 'exchange_rate', 'label' => 'Exchange rate', 'type' => 'number',
                'value' => $payload['exchange_rate'] ?? 1, 'display' => (string) ($payload['exchange_rate'] ?? 1),
            ],
            [
                'key' => 'datetime', 'label' => 'When', 'type' => 'datetime',
                'value' => $payload['datetime'] ?? null, 'display' => (string) ($payload['datetime'] ?? 'Now'),
            ],
        ];
    }
}
