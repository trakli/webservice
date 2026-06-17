<?php

namespace App\Ai\Tools\Render;

use App\Services\StatsToolService;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Draws headline KPI cards (balance, income, expenses, net cash flow, savings
 * rate) from the user's authoritative overview stats.
 */
class RenderKpiTool extends AbstractRenderTool
{
    public function __construct(private StatsToolService $stats)
    {
    }

    public function name(): string
    {
        return 'render_kpi';
    }

    public function description(): string
    {
        return 'Render headline KPI cards (total balance, income, expenses, net cash flow, '
            . 'savings rate) for a period. Use when the user wants an at-a-glance summary.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('start_date', 'Window start (YYYY-MM-DD). Defaults to 30 days ago.', required: false),
            ParameterSpec::string('end_date', 'Window end (YYYY-MM-DD). Defaults to today.', required: false),
        ];
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $user = $context->user;

        if ($user === null) {
            return ['error' => 'No authenticated user in context.'];
        }

        $data = $this->stats->section($user, 'overview', null, $arguments['start_date'] ?? null, $arguments['end_date'] ?? null);
        $overview = $data['overview'] ?? [];
        $currency = $data['currency'] ?? null;

        $items = [
            ['label' => 'Total balance', 'value' => $overview['total_balance'] ?? 0, 'currency' => $currency],
            ['label' => 'Income', 'value' => $overview['total_income'] ?? 0, 'currency' => $currency],
            ['label' => 'Expenses', 'value' => $overview['total_expenses'] ?? 0, 'currency' => $currency],
            ['label' => 'Net cash flow', 'value' => $overview['net_cash_flow'] ?? 0, 'currency' => $currency],
            ['label' => 'Savings rate', 'value' => round((float) ($overview['savings_rate'] ?? 0), 1), 'unit' => '%'],
        ];

        $this->collector()->add($this->blocks()->kpi($items));

        return 'Rendered KPI cards for the period.';
    }
}
