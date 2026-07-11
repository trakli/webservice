<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListWalletsTool extends Tool
{
    protected string $description = 'List the authenticated user\'s wallets with their id, name, type, currency, and current balance.';

    public function handle(Request $request): Response
    {
        $wallets = $request->user()->wallets()
            ->get(['id', 'name', 'type', 'currency', 'balance'])
            ->all();

        return Response::json(['wallets' => $wallets]);
    }
}
