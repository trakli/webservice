<?php

namespace App\Ai\Tools\Render;

use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Draws a chronological feed of events (transactions, milestones). Use for
 * "what happened when" answers where order in time is the point.
 */
class RenderTimelineTool extends AbstractRenderTool
{
    public function name(): string
    {
        return 'render_timeline';
    }

    public function description(): string
    {
        return 'Render a chronological timeline. Pass items_json as a JSON array of objects with '
            . '{date, title, description?, amount?, currency?}, optionally a title. Use for activity '
            . 'feeds or event histories.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('items_json', 'A JSON array of {date, title, description?, amount?, currency?} objects.'),
            ParameterSpec::string('title', 'Optional timeline title.', required: false),
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

        $this->collector()->add($this->blocks()->timeline($items, $arguments['title'] ?? null));

        return 'Rendered a timeline with ' . count($items) . ' event(s).';
    }
}
