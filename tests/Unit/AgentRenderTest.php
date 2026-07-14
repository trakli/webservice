<?php

namespace Tests\Unit;

use App\Ai\BlockBuilder;
use App\Ai\BlockCollector;
use App\Ai\Tools\Render\RenderTableTool;
use Whilesmart\Agents\ValueObjects\ToolContext;
use Tests\TestCase;

class AgentRenderTest extends TestCase
{
    public function test_render_table_tool_collects_a_typed_table_block(): void
    {
        $collector = new BlockCollector();
        $this->app->instance(BlockCollector::class, $collector);

        $tool = $this->app->make(RenderTableTool::class);

        $msg = $tool->handle([
            'rows_json' => json_encode([
                ['category' => 'Food', 'total' => 120],
                ['category' => 'Rent', 'total' => 500],
            ]),
            'title' => 'Spending',
        ], ToolContext::guest());

        $this->assertStringContainsString('2 row', $msg);
        $blocks = $collector->all();
        $this->assertCount(1, $blocks);
        $this->assertSame('table', $blocks[0]['type']);
        $this->assertSame(['category', 'total'], $blocks[0]['columns']);
        $this->assertSame('Spending', $blocks[0]['title']);
    }

    public function test_render_table_tool_rejects_non_array_payload(): void
    {
        $this->app->instance(BlockCollector::class, new BlockCollector());
        $tool = $this->app->make(RenderTableTool::class);

        $out = $tool->handle(['rows_json' => 'not json'], ToolContext::guest());

        $this->assertArrayHasKey('error', $out);
    }

    public function test_open_canvas_and_render_markdown_compose_a_document(): void
    {
        // The agent composes a canvas by opening it then rendering pieces in order.
        $collector = new BlockCollector();
        $this->app->instance(BlockCollector::class, $collector);

        $this->app->make(\App\Ai\Tools\Render\OpenCanvasTool::class)
            ->handle(['title' => 'June Spending Report'], ToolContext::guest());

        $this->assertSame('June Spending Report', $collector->canvasTitle());

        $this->app->make(\App\Ai\Tools\Render\RenderMarkdownTool::class)
            ->handle(['markdown' => '## Top categories'], ToolContext::guest());
        $this->app->make(RenderTableTool::class)
            ->handle(['rows_json' => json_encode([['category' => 'Food', 'total' => 120]])], ToolContext::guest());

        $blocks = $collector->all();
        $this->assertSame('markdown', $blocks[0]['type']);
        $this->assertSame('table', $blocks[1]['type']);
    }

    public function test_canvas_block_wraps_composed_blocks(): void
    {
        $b = new BlockBuilder();
        $canvas = $b->canvas('My Document', [
            $b->markdown('Intro'),
            $b->table(['a'], [['a' => 1]]),
        ]);

        $this->assertSame('canvas', $canvas['type']);
        $this->assertSame('My Document', $canvas['title']);
        $this->assertCount(2, $canvas['blocks']);
    }

    public function test_render_chart_tool_charts_arbitrary_rows(): void
    {
        $collector = new BlockCollector();
        $this->app->instance(BlockCollector::class, $collector);

        $tool = $this->app->make(\App\Ai\Tools\Render\RenderChartTool::class);

        $msg = $tool->handle([
            'rows_json' => json_encode([
                ['item' => 'Rent', 'amount' => 1200],
                ['item' => 'Car', 'amount' => 800],
            ]),
            'chart_hint' => 'pie',
            'title' => 'Top purchases',
        ], ToolContext::guest());

        $this->assertStringContainsString('supplied data', $msg);
        $blocks = $collector->all();
        $this->assertCount(1, $blocks);
        $this->assertSame('chart', $blocks[0]['type']);
        $this->assertSame('pie', $blocks[0]['chart_hint']);
        $this->assertSame('custom', $blocks[0]['dataset_ref']);
        $this->assertCount(2, $blocks[0]['data']);
    }

    public function test_render_chart_tool_requires_dataset_or_rows(): void
    {
        $this->app->instance(BlockCollector::class, new BlockCollector());
        $tool = $this->app->make(\App\Ai\Tools\Render\RenderChartTool::class);

        $out = $tool->handle([], ToolContext::guest());

        $this->assertArrayHasKey('error', $out);
    }

    public function test_block_builder_kpi_and_chart_shapes(): void
    {
        $b = new BlockBuilder();

        $kpi = $b->kpi([['label' => 'Income', 'value' => 100]], 'Summary');
        $this->assertSame('kpi', $kpi['type']);
        $this->assertSame('Summary', $kpi['title']);

        $chart = $b->chart('donut', 'category_spending', ['labels' => []]);
        $this->assertSame('chart', $chart['type']);
        $this->assertSame('donut', $chart['chart_hint']);
        $this->assertSame('category_spending', $chart['dataset_ref']);
    }
}
