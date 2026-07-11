<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Auth\McpGateRegistrar;
use App\Services\TransactionWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class RecordTransactionTool extends Tool
{
    protected string $description = 'Record an income or expense against one of the user\'s wallets. Requires the transactions.write permission.';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'amount' => $schema->number()->description('Positive transaction amount.')->required(),
            'type' => $schema->string()->description('"income" or "expense".')->required(),
            'wallet_id' => $schema->integer()->description('Wallet id (get it from list-wallets). Provide this or wallet_name.'),
            'wallet_name' => $schema->string()->description('Exact wallet name, if you do not have the id.'),
            'party_id' => $schema->integer()->description('Optional party id (get it from list-parties).'),
            'description' => $schema->string()->description('Free text describing the transaction.'),
            'datetime' => $schema->string()->description('When it happened, ISO 8601. Defaults to now.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if (! McpGateRegistrar::allows($request->user(), 'transactions.write')) {
            return Response::json(['error' => 'Permission denied: transactions.write']);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|gt:0',
            'type' => 'required|in:income,expense',
            'wallet_id' => 'required_without:wallet_name|integer',
            'wallet_name' => 'required_without:wallet_id|string',
            'party_id' => 'sometimes|integer',
            'description' => 'sometimes|string',
            'datetime' => 'sometimes|date',
        ]);

        $user = $request->user();

        $walletId = $validated['wallet_id'] ?? $user->wallets()
            ->where('name', $validated['wallet_name'] ?? null)
            ->value('id');

        if (! $walletId) {
            return Response::json(['error' => 'No wallet matched the given id or name.']);
        }

        $data = [
            'amount' => $validated['amount'],
            'type' => $validated['type'],
            'wallet_id' => $walletId,
            'party_id' => $validated['party_id'] ?? null,
            'description' => $validated['description'] ?? null,
            'datetime' => $validated['datetime'] ?? now(),
        ];

        $writer = app(TransactionWriter::class);
        $writer->validateOwnership($user, $data);

        $transaction = DB::transaction(fn () => $writer->createCore($user, $data));

        return Response::json(['transaction' => $transaction]);
    }
}
