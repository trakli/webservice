<?php

namespace App\Ai\Tools\Read;

use Whilesmart\Agents\Enums\ToolPermission;
use Whilesmart\Agents\Tools\AbstractTool;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Lists the user's parties (people or organizations they transact with) so the
 * agent can match a spoken name to a real party before recording or attaching.
 */
class ListPartiesTool extends AbstractTool
{
    public function name(): string
    {
        return 'list_parties';
    }

    public function description(): string
    {
        return 'List the user\'s parties (id, name, type). Call this to find the exact party a '
            . 'user refers to before recording a transaction against it.';
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
            'parties' => $user->parties()
                ->get(['id', 'name', 'type'])
                ->map(fn ($party) => [
                    'id' => $party->id,
                    'name' => $party->name,
                    'type' => $party->type,
                ])
                ->all(),
        ];
    }
}
