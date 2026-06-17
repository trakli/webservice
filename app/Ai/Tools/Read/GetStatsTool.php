<?php

namespace App\Ai\Tools\Read;

use App\Services\StatsToolService;
use Whilesmart\Agents\Enums\ToolPermission;
use Whilesmart\Agents\Tools\AbstractTool;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Returns a computed analytics section for the user (the same numbers the stats
 * screen shows). Use it to ground figures before answering or rendering a widget.
 */
class GetStatsTool extends AbstractTool
{
    public function __construct(private StatsToolService $stats)
    {
    }

    public function name(): string
    {
        return 'get_stats';
    }

    public function description(): string
    {
        return 'Get pre-computed financial analytics for the user: balances, income, expenses, '
            . 'savings rate, top categories, parties and cash flow. Choose a section. Returns '
            . 'authoritative numbers; never invent figures.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::enum('section', 'Which analytics section to compute.', ['overview', 'activity', 'comparisons', 'categories', 'parties', 'cashflow']),
            ParameterSpec::enum('period', 'Bucketing period for trends.', ['day', 'week', 'month', 'year'], required: false),
            ParameterSpec::string('start_date', 'Window start (YYYY-MM-DD). Defaults to 30 days ago.', required: false),
            ParameterSpec::string('end_date', 'Window end (YYYY-MM-DD). Defaults to today.', required: false),
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

        return $this->stats->section(
            $user,
            $arguments['section'] ?? 'overview',
            $arguments['period'] ?? null,
            $arguments['start_date'] ?? null,
            $arguments['end_date'] ?? null,
        );
    }
}
