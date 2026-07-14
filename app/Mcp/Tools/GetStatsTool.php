<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\StatsToolService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetStatsTool extends Tool
{
    protected string $name = 'get-stats';

    protected string $description = 'Pre-computed financial analytics for the user: balances, income, expenses, '
        . 'top categories and parties, cash flow, and net-worth position. Returns authoritative figures.';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'section' => $schema->string()->description('One of: overview, activity, comparisons, categories, parties, cashflow, position.')->required(),
            'period' => $schema->string()->description('Trend bucket: day, week, month, or year.'),
            'start_date' => $schema->string()->description('Window start, YYYY-MM-DD. Defaults to 30 days ago.'),
            'end_date' => $schema->string()->description('Window end, YYYY-MM-DD. Defaults to today.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'section' => 'required|in:overview,activity,comparisons,categories,parties,cashflow,position',
            'period' => 'sometimes|in:day,week,month,year',
            'start_date' => 'sometimes|date_format:Y-m-d',
            'end_date' => 'sometimes|date_format:Y-m-d',
        ]);

        $data = app(StatsToolService::class)->section(
            $request->user(),
            $validated['section'],
            $validated['period'] ?? null,
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
        );

        return Response::json($data);
    }
}
