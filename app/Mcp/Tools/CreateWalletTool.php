<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Contracts\Entitlements;
use App\Mcp\Auth\McpGateRegistrar;
use App\Models\Wallet;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateWalletTool extends Tool
{
    protected string $description = 'Create a new wallet for the authenticated user. Requires the wallets.write permission.';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Wallet name.')->required(),
            'type' => $schema->string()->description('One of: bank, cash, credit_card, mobile.')->required(),
            'currency' => $schema->string()->description('ISO 4217 currency code, e.g. USD.')->required(),
            'balance' => $schema->number()->description('Opening balance. Defaults to 0.'),
            'description' => $schema->string()->description('Optional note.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if (! McpGateRegistrar::allows($request->user(), 'wallets.write')) {
            return Response::json(['error' => 'Permission denied: wallets.write']);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:bank,cash,credit_card,mobile',
            'currency' => 'required|string|size:3',
            'balance' => 'sometimes|numeric|decimal:0,4',
            'description' => 'sometimes|string',
        ]);

        $user = $request->user();

        $existing = $user->wallets()
            ->where('name', $data['name'])
            ->where('currency', $data['currency'])
            ->first();

        if ($existing) {
            return Response::json(['wallet' => $existing, 'message' => 'Wallet already exists']);
        }

        $limit = app(Entitlements::class)->limit($user, 'max_wallets');
        if ($limit !== null && $user->wallets()->count() >= $limit) {
            return Response::json(['error' => 'You have reached the maximum number of wallets allowed.']);
        }

        /** @var Wallet $wallet */
        $wallet = $user->wallets()->create($data + ['user_id' => $user->id]);

        return Response::json(['wallet' => $wallet]);
    }
}
