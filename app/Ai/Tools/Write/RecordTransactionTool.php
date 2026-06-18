<?php

namespace App\Ai\Tools\Write;

use InvalidArgumentException;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Proposes recording an income or expense transaction. Resolves the wallet and
 * any categories by name, scoped to the user, and leaves execution to confirm.
 */
class RecordTransactionTool extends AbstractWriteTool
{
    use ResolvesUserResources;

    public function name(): string
    {
        return 'record_transaction';
    }

    public function actionType(): string
    {
        return 'transaction.create';
    }

    public function description(): string
    {
        return 'Propose recording a transaction (income or expense). Provide the amount, type, and '
            . 'the wallet by name. Optionally a description, category names, and an ISO datetime. '
            . 'The user confirms before it is saved.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::number('amount', 'The transaction amount, a positive number.'),
            ParameterSpec::enum('type', 'Whether money came in or went out.', ['income', 'expense']),
            ParameterSpec::number('wallet_id', 'The id of the wallet (preferred; get it from list_wallets).', required: false),
            ParameterSpec::string('wallet_name', 'The exact wallet name, if you do not have its id.', required: false),
            ParameterSpec::string('description', 'Free text describing the purchase, e.g. "coffee". This is NOT a category.', required: false),
            ParameterSpec::arrayOf('category_names', 'ONLY categories the user explicitly named and that exist. Omit otherwise.', required: false),
            ParameterSpec::number('party_id', 'The id of the party this transaction is with (preferred; get it from list_parties).', required: false),
            ParameterSpec::string('party_name', 'The exact party name, if you do not have its id and the user named one.', required: false),
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

        $type = $arguments['type'] ?? null;
        if (! in_array($type, ['income', 'expense'], true)) {
            throw new InvalidArgumentException('Type must be income or expense.');
        }

        $walletId = $this->resolveWalletId($user, $arguments);
        $categoryIds = $this->resolveCategoryIds($user, (array) ($arguments['category_names'] ?? []));
        $partyId = $this->resolvePartyId($user, $arguments);

        return array_filter([
            'amount' => $amount,
            'type' => $type,
            'wallet_id' => $walletId,
            'party_id' => $partyId,
            'description' => $arguments['description'] ?? null,
            'datetime' => $arguments['datetime'] ?? null,
            'categories' => $categoryIds,
        ], fn ($value) => $value !== null && $value !== []);
    }

    protected function summarize(array $payload, ToolContext $context): string
    {
        $verb = $payload['type'] === 'income' ? 'Record income of' : 'Log an expense of';
        $desc = isset($payload['description']) ? " for {$payload['description']}" : '';

        return "{$verb} {$payload['amount']}{$desc}.";
    }

    protected function reviewFields(array $payload, ToolContext $context): array
    {
        $user = $context->user;

        $type = (string) ($payload['type'] ?? 'expense');
        $walletId = $payload['wallet_id'] ?? null;
        $walletName = $walletId && $user !== null
            ? optional($user->wallets()->find($walletId))->name
            : null;
        $categoryIds = (array) ($payload['categories'] ?? []);
        $categoryNames = $categoryIds !== [] && $user !== null
            ? $user->categories()->whereIn('id', $categoryIds)->pluck('name')->all()
            : [];

        $partyId = $payload['party_id'] ?? null;
        $partyName = $partyId && $user !== null
            ? optional($user->parties()->find($partyId))->name
            : null;

        return [
            ['key' => 'type', 'label' => 'Type', 'type' => 'enum', 'value' => $type, 'display' => ucfirst($type), 'options' => ['income', 'expense']],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'number', 'value' => $payload['amount'] ?? 0, 'display' => (string) ($payload['amount'] ?? '')],
            ['key' => 'wallet_id', 'label' => 'Wallet', 'type' => 'wallet', 'value' => $walletId, 'display' => $walletName ?? ('#' . $walletId)],
            ['key' => 'party_id', 'label' => 'Party', 'type' => 'party', 'value' => $partyId, 'display' => $partyName ?? ($partyId ? '#' . $partyId : '')],
            [
                'key' => 'description',
                'label' => 'Description',
                'type' => 'text',
                'value' => $payload['description'] ?? '',
                'display' => (string) ($payload['description'] ?? ''),
            ],
            [
                'key' => 'categories',
                'label' => 'Categories',
                'type' => 'categories',
                'value' => array_map('intval', $categoryIds),
                'display' => implode(', ', $categoryNames),
            ],
            [
                'key' => 'datetime',
                'label' => 'When',
                'type' => 'datetime',
                'value' => $payload['datetime'] ?? null,
                'display' => (string) ($payload['datetime'] ?? 'Now'),
            ],
        ];
    }
}
