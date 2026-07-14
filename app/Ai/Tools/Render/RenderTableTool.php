<?php

namespace App\Ai\Tools\Render;

use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Draws a table from rows the model supplies (typically derived from a prior
 * smartql.query result). Rows are passed as a JSON array of objects; the
 * component adapts a single-row table into a record card.
 */
class RenderTableTool extends AbstractRenderTool
{
    public function name(): string
    {
        return 'render_table';
    }

    public function description(): string
    {
        return 'Render a data table. Pass rows_json as a JSON array of flat objects, e.g. '
            . '[{"category":"Food","total":120}]. Optionally pass a title. Use after querying data '
            . 'the user wants to see laid out.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('rows_json', 'A JSON array of flat objects (the table rows).'),
            ParameterSpec::string('title', 'Optional table title.', required: false),
        ];
    }

    /** @SuppressWarnings(PHPMD.UnusedFormalParameter) */
    public function handle(array $arguments, ToolContext $context): string|array
    {
        $rows = json_decode((string) ($arguments['rows_json'] ?? ''), true);

        if (! is_array($rows) || $rows === []) {
            return ['error' => 'rows_json must be a non-empty JSON array of objects.'];
        }

        // Normalize to a list of associative rows and derive columns from the first row.
        $rows = array_values(array_filter($rows, 'is_array'));
        if ($rows === []) {
            return ['error' => 'rows_json must contain objects.'];
        }

        $columns = array_keys($rows[0]);

        $this->collector()->add($this->blocks()->table($columns, $rows, $arguments['title'] ?? null));

        return 'Rendered a table with ' . count($rows) . ' row(s).';
    }
}
