<?php

namespace App\Ai\Tools\Read;

use Whilesmart\Agents\Enums\ToolPermission;
use Whilesmart\Agents\Tools\AbstractTool;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Lists the user's categories so the agent can tell whether a category the user
 * named actually exists, rather than inventing one from a description.
 */
class ListCategoriesTool extends AbstractTool
{
    public function name(): string
    {
        return 'list_categories';
    }

    public function description(): string
    {
        return 'List the user\'s existing categories (id, name, type). Categories are optional on a '
            . 'transaction; use this only to check whether a category the user explicitly named exists.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::enum('type', 'Filter by category type.', ['income', 'expense'], required: false),
        ];
    }

    public function permission(): ToolPermission
    {
        return ToolPermission::READ;
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $user = $context->user;
        if ($user === null) {
            return ['error' => 'No authenticated user in context.'];
        }

        $query = $user->categories();
        if (in_array($arguments['type'] ?? null, ['income', 'expense'], true)) {
            $query->where('type', $arguments['type']);
        }

        return [
            'categories' => $query
                ->get(['id', 'name', 'type'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'type' => $c->type])
                ->all(),
        ];
    }
}
