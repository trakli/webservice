<?php

namespace App\Ai\Tools\Write;

use InvalidArgumentException;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Gives each transaction its own category in one call, for the common "sort out
 * my uncategorized transactions" sweep where every row needs a different answer.
 * Pairing each id with its category in one entry keeps them from drifting apart,
 * which is what two parallel lists would risk.
 */
class AssignTransactionCategoriesTool extends AbstractWriteTool
{
    use DescribesTransactionCategories;

    public function name(): string
    {
        return 'assign_transaction_categories';
    }

    public function actionType(): string
    {
        return 'transaction.categorize';
    }

    public function description(): string
    {
        return 'Propose a DIFFERENT category for each of several transactions in one call. '
            . 'Give one entry per transaction, pairing its id with the category name it should get. '
            . 'Use this to categorize a set of transactions where they do not all get the same '
            . 'category; when they all get the same one, use `categorize_transactions`. '
            . 'A transaction holds one category, so this replaces whatever it had.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::arrayOfObject('assignments', 'One entry per transaction to categorize.', [
                ParameterSpec::number('transaction_id', 'The id of the transaction.'),
                ParameterSpec::string('category_name', 'The name of the category this transaction should get.'),
            ]),
        ];
    }

    protected function buildPayload(array $arguments, ToolContext $context): array
    {
        return $this->buildPayloads($arguments, $context)[0];
    }

    protected function buildPayloads(array $arguments, ToolContext $context): array
    {
        $user = $context->user;

        $assignments = collect($arguments['assignments'] ?? [])
            ->filter(fn ($entry): bool => is_array($entry))
            ->map(fn (array $entry): array => [
                'transaction_id' => (int) ($entry['transaction_id'] ?? 0),
                'category_name' => trim((string) ($entry['category_name'] ?? '')),
            ])
            ->filter(fn (array $entry): bool => $entry['transaction_id'] > 0 && $entry['category_name'] !== '')
            // The last word wins if the model names the same transaction twice,
            // rather than proposing two conflicting changes to one row.
            ->keyBy('transaction_id')
            ->values();

        if ($assignments->isEmpty()) {
            throw new InvalidArgumentException('At least one transaction id paired with a category name is required.');
        }

        $owned = $user->transactions()->whereKey($assignments->pluck('transaction_id'))->pluck('id');
        $missing = $assignments->pluck('transaction_id')->diff($owned);

        if ($missing->isNotEmpty()) {
            throw new InvalidArgumentException(
                'These transactions were not found among your records: ' . $missing->implode(', ') . '.'
            );
        }

        $names = $assignments->pluck('category_name')->unique()->values()->all();
        $byName = $this->categoryIdsByName($user, $names);

        // Reported together rather than one at a time, so the agent can fix a
        // whole sweep in one turn instead of failing on each name in sequence.
        $unknown = collect($names)->reject(fn (string $name): bool => isset($byName[mb_strtolower($name)]));
        if ($unknown->isNotEmpty()) {
            throw new InvalidArgumentException(
                'These categories do not exist yet: ' . $unknown->implode(', ')
                . '. Propose creating them first, or use ones that exist.'
            );
        }

        return $assignments
            ->map(fn (array $entry): array => [
                'transaction_id' => $entry['transaction_id'],
                'categories' => [$byName[mb_strtolower($entry['category_name'])]],
            ])
            ->all();
    }

    protected function summarizeBatch(array $payloads, ToolContext $context): string
    {
        $count = count($payloads);
        $categories = collect($payloads)
            ->map(fn (array $payload): ?string => $this->categoryName($context, $payload['categories'][0] ?? null))
            ->filter()
            ->unique();

        $noun = $count === 1 ? 'transaction' : 'transactions';
        $across = $categories->count() === 1
            ? "as {$categories->first()}"
            : "across {$categories->count()} categories";

        return "Categorize {$count} {$noun} {$across}";
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, int>
     */
    private function categoryIdsByName($user, array $names): array
    {
        return $user->categories()
            ->get(['id', 'name'])
            ->mapWithKeys(fn ($category): array => [mb_strtolower($category->name) => $category->id])
            ->only(array_map('mb_strtolower', $names))
            ->all();
    }
}
