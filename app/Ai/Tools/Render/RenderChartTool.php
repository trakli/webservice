<?php

namespace App\Ai\Tools\Render;

use App\Services\StatsToolService;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Draws a chart from one of the user's authoritative analytics datasets. The
 * dataset determines the section computed; the frontend chart component adapts
 * the final visual form, using chart_hint as a preference.
 */
class RenderChartTool extends AbstractRenderTool
{
    /** @var array<string, array{section: string, hint: string, top_level?: bool}> */
    private const DATASETS = [
        'category_spending' => ['section' => 'categories', 'hint' => 'donut'],
        'income_sources' => ['section' => 'categories', 'hint' => 'pie'],
        'party_spending' => ['section' => 'parties', 'hint' => 'hbar'],
        'party_income' => ['section' => 'parties', 'hint' => 'bar'],
        'monthly_cash_flow' => ['section' => 'cashflow', 'hint' => 'line'],
        'expense_by_wallet' => ['section' => 'cashflow', 'hint' => 'bar'],
        'spending_trends' => ['section' => 'cashflow', 'hint' => 'line', 'top_level' => true],
    ];

    public function __construct(private StatsToolService $stats)
    {
    }

    public function name(): string
    {
        return 'render_chart';
    }

    public function description(): string
    {
        return 'Render a chart of the user\'s finances. Datasets: category_spending, income_sources, '
            . 'party_spending, party_income, monthly_cash_flow, expense_by_wallet, spending_trends. '
            . 'chart_hint picks the shape: pie/donut/polarArea for the composition of one total, '
            . 'line/area for trends over time, bar/hbar for ranking categories, treemap for nested '
            . 'composition, radialBar for a single proportion. If omitted, a sensible default is used.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::enum('dataset', 'Which dataset to chart.', array_keys(self::DATASETS)),
            ParameterSpec::enum(
                'chart_hint',
                'Preferred chart type.',
                ['line', 'area', 'bar', 'hbar', 'pie', 'donut', 'polarArea', 'radialBar', 'scatter', 'treemap'],
                required: false
            ),
            ParameterSpec::string('start_date', 'Window start (YYYY-MM-DD).', required: false),
            ParameterSpec::string('end_date', 'Window end (YYYY-MM-DD).', required: false),
        ];
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $user = $context->user;

        if ($user === null) {
            return ['error' => 'No authenticated user in context.'];
        }

        $dataset = $arguments['dataset'] ?? null;
        if (! isset(self::DATASETS[$dataset])) {
            return ['error' => 'Unknown dataset.'];
        }

        $spec = self::DATASETS[$dataset];
        $computed = $this->stats->section($user, $spec['section'], 'month', $arguments['start_date'] ?? null, $arguments['end_date'] ?? null);

        $data = ($spec['top_level'] ?? false)
            ? ($computed[$dataset] ?? [])
            : ($computed['charts'][$dataset] ?? []);

        $hint = $arguments['chart_hint'] ?? $spec['hint'];

        $this->collector()->add($this->blocks()->chart($hint, $dataset, $data));

        return "Rendered a {$hint} chart of {$dataset}.";
    }
}
