<?php

namespace App\Ai\Tools\Write;

use App\Models\Transaction;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Review-card wording for the categorize tools. Ids are how the agent addresses
 * a record; they mean nothing to the person confirming it, so a card names the
 * transaction it will change and the category it will put there.
 */
trait DescribesTransactionCategories
{
    protected function categoryName(ToolContext $context, ?int $categoryId): ?string
    {
        if ($categoryId === null || $context->user === null) {
            return null;
        }

        return $context->user->categories()->whereKey($categoryId)->value('name');
    }

    protected function transactionFor(ToolContext $context, ?int $transactionId): ?Transaction
    {
        if ($transactionId === null || $context->user === null) {
            return null;
        }

        return $context->user->transactions()->with('wallet')->find($transactionId);
    }

    /**
     * How a transaction reads on a card: what it was, for how much, when. Enough
     * for the user to recognise the row without opening it.
     */
    protected function describeTransaction(?Transaction $transaction, ?int $fallbackId): string
    {
        if ($transaction === null) {
            return $fallbackId ? "Transaction #{$fallbackId}" : 'Transaction';
        }

        $parts = array_filter([
            $transaction->description ?: 'Untitled',
            $transaction->amount !== null
                ? trim(($transaction->wallet->currency ?? '') . ' ' . $transaction->amount)
                : null,
            $transaction->datetime?->format('j M Y'),
        ]);

        return implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    protected function reviewFields(array $payload, ToolContext $context): array
    {
        $transactionId = isset($payload['transaction_id']) ? (int) $payload['transaction_id'] : null;
        $categoryId = $payload['categories'][0] ?? null;
        $categoryId = $categoryId !== null ? (int) $categoryId : null;

        $transaction = $this->transactionFor($context, $transactionId);
        $current = $transaction?->categories->first()?->name;

        return array_values(array_filter([
            [
                // Read-only: the agent chose which transaction, and letting the
                // user swap it here would silently retarget the whole action.
                'key' => 'transaction_id',
                'label' => 'Transaction',
                'type' => 'readonly',
                'value' => $transactionId,
                'display' => $this->describeTransaction($transaction, $transactionId),
            ],
            $current ? [
                'key' => 'current_category',
                'label' => 'Currently',
                'type' => 'readonly',
                'value' => $current,
                'display' => $current,
            ] : null,
            [
                'key' => 'categories',
                'label' => 'Category',
                'type' => 'category',
                'value' => $categoryId,
                'display' => $this->categoryName($context, $categoryId) ?? '',
            ],
        ]));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function summarize(array $payload, ToolContext $context): string
    {
        $name = $this->categoryName($context, $payload['categories'][0] ?? null) ?? 'a category';
        $transaction = $this->transactionFor($context, (int) ($payload['transaction_id'] ?? 0));

        return 'Categorize ' . $this->describeTransaction($transaction, $payload['transaction_id'] ?? null) . " as {$name}";
    }
}
