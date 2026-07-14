<?php

namespace App\Ai\Tools\Render;

use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Opens a canvas document for the current turn. After calling this, the widgets
 * the agent renders (render_markdown, render_table, render_chart, render_kpi) are
 * assembled, in call order, into a single titled report shown in the side canvas
 * rather than as separate chat bubbles. Use it for anything report- or
 * document-like: multiple sections, tables and charts woven with narrative.
 */
class OpenCanvasTool extends AbstractRenderTool
{
    public function name(): string
    {
        return 'open_canvas';
    }

    public function description(): string
    {
        return 'Start a canvas document for a rich, multi-part answer (a report, dashboard or any '
            . 'layout with sections, tables and charts). Call this FIRST with a title, then build the '
            . 'document by calling render_markdown / render_table / render_chart / render_kpi in the '
            . 'order the content should appear. Everything you render becomes one document in the canvas.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('title', 'The document title, e.g. "June Spending Report".'),
        ];
    }

    /** @SuppressWarnings(PHPMD.UnusedFormalParameter) */
    public function handle(array $arguments, ToolContext $context): string|array
    {
        $title = trim((string) ($arguments['title'] ?? 'Report'));
        $this->collector()->openCanvas($title);

        return "Canvas \"{$title}\" opened. Now build it: call render_markdown for prose sections and "
            . 'render_table / render_chart / render_kpi for data, in the order they should appear.';
    }
}
