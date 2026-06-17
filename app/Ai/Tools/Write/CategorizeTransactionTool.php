<?php

namespace App\Ai\Tools\Write;

use InvalidArgumentException;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Proposes attaching categories to an existing transaction. The transaction id
 * typically comes from a prior smartql.query.
 */
class CategorizeTransactionTool extends AbstractWriteTool
{
    use ResolvesUserResources;

    public function name(): string
    {
        return 'categorize_transaction';
    }

    public function actionType(): string
    {
        return 'transaction.categorize';
    }

    public function description(): string
    {
        return 'Propose tagging an existing transaction with one or more categories (by name). '
            . 'Provide the transaction id and the category names.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::number('transaction_id', 'The id of the transaction to categorize.'),
            ParameterSpec::arrayOf('category_names', 'Category names to apply, e.g. ["Groceries"].'),
        ];
    }

    protected function buildPayload(array $arguments, ToolContext $context): array
    {
        $user = $context->user;

        $transactionId = (int) ($arguments['transaction_id'] ?? 0);
        if ($transactionId <= 0 || ! $user->transactions()->whereKey($transactionId)->exists()) {
            throw new InvalidArgumentException('That transaction was not found among your records.');
        }

        $categoryIds = $this->resolveCategoryIds($user, (array) ($arguments['category_names'] ?? []));
        if ($categoryIds === []) {
            throw new InvalidArgumentException('At least one existing category is required.');
        }

        return [
            'transaction_id' => $transactionId,
            'categories' => $categoryIds,
        ];
    }

    protected function summarize(array $payload, ToolContext $context): string
    {
        $count = count($payload['categories']);

        return "Tag transaction #{$payload['transaction_id']} with {$count} categor" . ($count === 1 ? 'y' : 'ies') . '.';
    }
}
