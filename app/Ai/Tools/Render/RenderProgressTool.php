<?php

namespace App\Ai\Tools\Render;

use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Draws progress bars toward one or more targets (budget vs actual, savings
 * goal). Use when the point is "how far along", not the raw figure.
 */
class RenderProgressTool extends AbstractRenderTool
{
    public function name(): string
    {
        return 'render_progress';
    }

    public function description(): string
    {
        return 'Render progress toward targets. Pass items_json as a JSON array of objects with '
            . '{label, current, target, currency?}, optionally a title. Use for budget-vs-actual or '
            . 'savings-goal tracking.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('items_json', 'A JSON array of {label, current, target, currency?} objects.'),
            ParameterSpec::string('title', 'Optional title.', required: false),
        ];
    }

    /** @SuppressWarnings(PHPMD.UnusedFormalParameter) */
    public function handle(array $arguments, ToolContext $context): string|array
    {
        $items = json_decode((string) ($arguments['items_json'] ?? ''), true);
        if (! is_array($items) || $items === []) {
            return ['error' => 'items_json must be a non-empty JSON array of objects.'];
        }

        $items = array_values(array_filter($items, 'is_array'));
        if ($items === []) {
            return ['error' => 'items_json must contain objects.'];
        }

        $this->collector()->add($this->blocks()->progress($items, $arguments['title'] ?? null));

        return 'Rendered progress for ' . count($items) . ' target(s).';
    }
}
