<?php

namespace App\Ai\Tools\Imports;

use App\Models\ImportSession;
use Illuminate\Support\Facades\Validator;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

class ListImportRowsTool extends ImportReviewTool
{
    public function name(): string
    {
        return 'list_import_rows';
    }

    public function description(): string
    {
        return 'Read draft import rows and totals before editing the review.';
    }

    public function parameters(): array
    {
        return [...parent::parameters(),
            ParameterSpec::string('contains', 'Text in description or counterparty', false),
            ParameterSpec::enum('unassigned', 'Missing assignment', ['party', 'category', 'wallet'], false),
            ParameterSpec::boolean('skipped', 'Filter skipped or included rows', false),
            ParameterSpec::arrayOf('ids', 'Suggestion ids', required: false),
        ];
    }

    protected function run(array $arguments, ToolContext $context, ImportSession $session): array
    {
        Validator::make($arguments, ['contains' => 'sometimes|string', 'unassigned' => 'sometimes|in:party,category,wallet', 'skipped' => 'sometimes|boolean', 'ids' => 'sometimes|array', 'ids.*' => 'string'])->validate();
        $all = collect($session->suggestions);
        $rows = $all;
        if (isset($arguments['contains'])) {
            $ids = $this->matchingIds($rows->all(), ['field' => 'any', 'mode' => 'contains', 'pattern' => $arguments['contains']]);
            $rows = $rows->whereIn('id', $ids);
        }
        if (isset($arguments['unassigned'])) {
            $rows = $rows->filter(fn ($row) => empty($row[$arguments['unassigned'].'_id']));
        }
        if (array_key_exists('skipped', $arguments)) {
            $rows = $rows->filter(fn ($row) => (bool) ($row['skip'] ?? false) === (bool) $arguments['skipped']);
        }
        if (isset($arguments['ids'])) {
            $rows = $rows->whereIn('id', $arguments['ids']);
        }

        return [
            'revision' => $session->revision,
            'rows' => $rows->take(100)->map(fn ($row) => [...$row, 'counterparty' => $row['counterparty'] ?? $row['party'] ?? null])->values()->all(),
            'total_matches' => $rows->count(),
            'totals' => [
                'row_count' => $all->count(), 'skipped' => $all->where('skip', true)->count(),
                'unassigned' => collect(['party', 'category', 'wallet'])->mapWithKeys(fn ($type) => [$type => $all->filter(fn ($row) => empty($row[$type.'_id']))->count()])->all(),
            ],
        ];
    }
}
