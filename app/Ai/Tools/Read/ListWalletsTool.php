<?php

namespace App\Ai\Tools\Read;

use Whilesmart\Agents\Enums\ToolPermission;
use Whilesmart\Agents\Tools\AbstractTool;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Lists the user's wallets so the agent can match a spoken name ("my credit
 * card") to a real wallet instead of guessing.
 */
class ListWalletsTool extends AbstractTool
{
    public function name(): string
    {
        return 'list_wallets';
    }

    public function description(): string
    {
        return 'List the user\'s wallets (id, name, type, currency). Call this to find the exact '
            . 'wallet a user refers to before recording or transferring money.';
    }

    public function permission(): ToolPermission
    {
        return ToolPermission::READ;
    }

    /** @SuppressWarnings(PHPMD.UnusedFormalParameter) */
    public function handle(array $arguments, ToolContext $context): string|array
    {
        $user = $context->user;
        if ($user === null) {
            return ['error' => 'No authenticated user in context.'];
        }

        return [
            'wallets' => $user->wallets()
                ->get(['id', 'name', 'type', 'currency'])
                ->map(fn ($wallet) => [
                    'id' => $wallet->id,
                    'name' => $wallet->name,
                    'type' => $wallet->type,
                    'currency' => $wallet->currency,
                ])
                ->all(),
        ];
    }
}
