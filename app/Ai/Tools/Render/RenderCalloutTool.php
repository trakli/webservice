<?php

namespace App\Ai\Tools\Render;

use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Draws a highlighted insight or alert (e.g. "Spending up 30% vs last month").
 * Use sparingly for the single most important takeaway, not for body prose.
 */
class RenderCalloutTool extends AbstractRenderTool
{
    public function name(): string
    {
        return 'render_callout';
    }

    public function description(): string
    {
        return 'Render one highlighted insight/alert. Pass text, an optional title, and a variant '
            . '(info, success, warning, danger). Use for a key takeaway, not ordinary prose.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('text', 'The insight or alert message.'),
            ParameterSpec::enum('variant', 'The accent style.', ['info', 'success', 'warning', 'danger'], required: false),
            ParameterSpec::string('title', 'Optional short heading.', required: false),
        ];
    }

    /** @SuppressWarnings(PHPMD.UnusedFormalParameter) */
    public function handle(array $arguments, ToolContext $context): string|array
    {
        $text = trim((string) ($arguments['text'] ?? ''));
        if ($text === '') {
            return ['error' => 'Callout text is required.'];
        }

        $this->collector()->add($this->blocks()->callout(
            $text,
            (string) ($arguments['variant'] ?? 'info'),
            $arguments['title'] ?? null,
        ));

        return 'Rendered a callout.';
    }
}
