<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListPartiesTool extends Tool
{
    protected string $name = 'list-parties';

    protected string $description = 'List the authenticated user\'s parties (people or organisations transactions are with), with their id and name.';

    public function handle(Request $request): Response
    {
        $parties = $request->user()->parties()
            ->get(['id', 'name'])
            ->map(fn ($party) => [
                'id' => $party->id,
                'name' => $party->name,
            ])
            ->all();

        return Response::json(['parties' => $parties]);
    }
}
