<?php

namespace App\Ai\Tools\Render;

use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Renders a block of GitHub-flavored Markdown as a section of the response.
 * Lets the agent place prose, headings and analysis between charts and tables
 * when composing a document, instead of cramming everything into one answer.
 */
class RenderMarkdownTool extends AbstractRenderTool
{
    public function name(): string
    {
        return 'render_markdown';
    }

    public function description(): string
    {
        return 'Render a section of GitHub-flavored Markdown (headings, prose, lists). Use it to '
            . 'narrate and structure a document around the tables and charts you render. For tabular '
            . 'data use render_table instead of writing a Markdown table by hand.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('markdown', 'The Markdown content for this section.'),
        ];
    }

    /** @SuppressWarnings(PHPMD.UnusedFormalParameter) */
    public function handle(array $arguments, ToolContext $context): string|array
    {
        $text = trim((string) ($arguments['markdown'] ?? ''));
        if ($text === '') {
            return ['error' => 'Markdown content is required.'];
        }

        $this->collector()->add($this->blocks()->markdown($text));

        return 'Added a markdown section.';
    }
}
