<?php

namespace App\Ai\Tools\Write;

use App\Models\Transaction;
use InvalidArgumentException;
use Whilesmart\Agents\Enums\ParameterType;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Puts one category on any number of transactions at once. The ids typically
 * come from a prior smartql.query. Proposing them together means the user
 * confirms the whole sweep once instead of a card per transaction.
 */
class CategorizeTransactionsTool extends AbstractWriteTool
{
    use DescribesTransactionCategories;
    use ResolvesUserResources;

    public function name(): string
    {
        return 'categorize_transactions';
    }

    public function actionType(): string
    {
        return 'transaction.categorize';
    }

    public function description(): string
    {
        return 'Propose putting ONE category on one or more existing transactions. '
            . 'Give the transaction ids and the single category name to apply to all of them. '
            . 'Use this when every transaction gets the same category; when they each need a '
            . 'different one, use `assign_transaction_categories` instead. A transaction holds '
            . 'one category, so this replaces whatever it had.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::arrayOf('transaction_ids', 'The ids of the transactions to categorize.', ParameterType::NUMBER),
            ParameterSpec::string('category_name', 'The single category name to apply to all of them.'),
        ];
    }

    protected function buildPayload(array $arguments, ToolContext $context): array
    {
        return $this->buildPayloads($arguments, $context)[0];
    }

    protected function buildPayloads(array $arguments, ToolContext $context): array
    {
        $user = $context->user;

        $ids = collect($arguments['transaction_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw new InvalidArgumentException('At least one transaction id is required.');
        }

        $owned = $user->transactions()->whereKey($ids)->pluck('id');
        $missing = $ids->diff($owned);

        if ($missing->isNotEmpty()) {
            throw new InvalidArgumentException(
                'These transactions were not found among your records: ' . $missing->implode(', ') . '.'
            );
        }

        $categoryIds = $this->resolveCategoryIds($user, [(string) ($arguments['category_name'] ?? '')]);
        if ($categoryIds === []) {
            throw new InvalidArgumentException('That category does not exist yet. Propose creating it first.');
        }

        return $owned
            ->map(fn (int $id): array => [
                'transaction_id' => $id,
                'categories' => [$categoryIds[0]],
            ])
            ->all();
    }

    protected function summarizeBatch(array $payloads, ToolContext $context): string
    {
        $count = count($payloads);
        $name = $this->categoryName($context, $payloads[0]['categories'][0] ?? null) ?? 'a category';
        $noun = $count === 1 ? 'transaction' : 'transactions';

        return "Categorize {$count} {$noun} as {$name}";
    }
}
