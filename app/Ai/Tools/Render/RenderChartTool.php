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
        return 'Render a chart. Two ways: (1) pass a built-in `dataset` (category_spending, '
            . 'income_sources, party_spending, party_income, monthly_cash_flow, expense_by_wallet, '
            . 'spending_trends) to chart the user\'s authoritative analytics; OR (2) pass `rows_json`, '
            . 'a JSON array of flat objects, to chart ANY data you already have, e.g. the rows from a '
            . 'smartql.query like top purchases. Use rows_json whenever the user wants a chart of '
            . 'something that is not a built-in dataset. chart_hint picks the shape: pie/donut/polarArea '
            . 'for the composition of one total, line/area for trends, bar/hbar for ranking, treemap for '
            . 'nested composition, radialBar for a single proportion.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::enum('dataset', 'A built-in dataset to chart. Omit when passing rows_json.', array_keys(self::DATASETS), required: false),
            ParameterSpec::string(
                'rows_json',
                'A JSON array of flat objects to chart directly, e.g. [{"item":"Rent","amount":1200}]. '
                    . 'Use for any non-dataset data such as smartql.query rows.',
                required: false,
            ),
            ParameterSpec::string('title', 'Optional chart title.', required: false),
            ParameterSpec::enum(
                'chart_hint',
                'Preferred chart type.',
                ['line', 'area', 'bar', 'hbar', 'pie', 'donut', 'polarArea', 'radialBar', 'scatter', 'treemap'],
                required: false
            ),
            ParameterSpec::string('start_date', 'Window start (YYYY-MM-DD). Only for built-in datasets.', required: false),
            ParameterSpec::string('end_date', 'Window end (YYYY-MM-DD). Only for built-in datasets.', required: false),
        ];
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $title = isset($arguments['title']) ? trim((string) $arguments['title']) : null;

        // Arbitrary data the model already has (e.g. smartql.query rows). This is
        // what lets the agent chart anything, not just the built-in datasets.
        if (! empty($arguments['rows_json'])) {
            $rows = json_decode((string) $arguments['rows_json'], true);
            $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
            if ($rows === []) {
                return ['error' => 'rows_json must be a non-empty JSON array of objects.'];
            }

            $hint = (string) ($arguments['chart_hint'] ?? '');
            $this->collector()->add($this->blocks()->chart($hint, 'custom', $rows, $title));

            return 'Rendered a chart of the supplied data (' . count($rows) . ' rows).';
        }

        $user = $context->user;
        if ($user === null) {
            return ['error' => 'No authenticated user in context.'];
        }

        $dataset = $arguments['dataset'] ?? null;
        if (! isset(self::DATASETS[$dataset])) {
            return ['error' => 'Provide either a known dataset or rows_json with the data to chart.'];
        }

        $spec = self::DATASETS[$dataset];
        $computed = $this->stats->section($user, $spec['section'], 'month', $arguments['start_date'] ?? null, $arguments['end_date'] ?? null);

        $data = ($spec['top_level'] ?? false)
            ? ($computed[$dataset] ?? [])
            : ($computed['charts'][$dataset] ?? []);

        $hint = $arguments['chart_hint'] ?? $spec['hint'];

        $this->collector()->add($this->blocks()->chart($hint, $dataset, $data, $title));

        return "Rendered a {$hint} chart of {$dataset}.";
    }
}
