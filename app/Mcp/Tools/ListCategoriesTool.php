<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListCategoriesTool extends Tool
{
    protected string $name = 'list-categories';

    protected string $description = 'List the authenticated user\'s categories with their id, name, and type (income or expense).';

    public function handle(Request $request): Response
    {
        $categories = $request->user()->categories()
            ->get(['id', 'name', 'type'])
            ->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'type' => $category->type,
            ])
            ->all();

        return Response::json(['categories' => $categories]);
    }
}
