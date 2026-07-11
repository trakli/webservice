<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListTransactionsTool extends Tool
{
    protected string $description = 'List the authenticated user\'s most recent transactions, newest first. Optionally filter by type and cap the count.';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Maximum number of transactions to return (1-100, default 20).'),
            'type' => $schema->string()->description('Filter by type: "income" or "expense". Omit for both.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'limit' => 'sometimes|integer|min:1|max:100',
            'type' => 'sometimes|in:income,expense',
        ]);

        $query = $request->user()->transactions()
            ->with(['wallet:id,name,currency', 'party:id,name'])
            ->latest('datetime');

        if (isset($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        $transactions = $query->limit($validated['limit'] ?? 20)->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'amount' => $t->amount,
                'type' => $t->type,
                'description' => $t->description,
                'datetime' => optional($t->datetime)->toIso8601String(),
                'wallet' => $t->wallet?->only(['id', 'name', 'currency']),
                'party' => $t->party?->only(['id', 'name']),
            ])
            ->all();

        return Response::json(['transactions' => $transactions]);
    }
}
