<?php

namespace App\Ai\Tools\Imports;

use App\Models\ImportSession;
use App\Services\ImportReviewService;
use App\Services\SuggestionEnricher;
use App\Types\TransactionSuggestion;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Whilesmart\Agents\Enums\ToolPermission;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

class RematchImportRowsTool extends ImportReviewTool
{
    public function name(): string
    {
        return 'rematch_import_rows';
    }

    public function description(): string
    {
        return 'Reclassify selected draft rows using guidance, preserving rows edited by the user.';
    }

    public function permission(): ToolPermission
    {
        return ToolPermission::WRITE;
    }

    public function parameters(): array
    {
        return [...parent::parameters(), ParameterSpec::arrayOf('ids', 'Suggestion ids, defaults to unassigned rows', required: false), ParameterSpec::string('instruction', 'Additional classification guidance')];
    }

    protected function run(array $arguments, ToolContext $context, ImportSession $session): array
    {
        Validator::make($arguments, ['ids' => 'sometimes|array', 'ids.*' => 'required|string', 'instruction' => 'required|string|max:2000'])->validate();
        if ($session->status !== 'ready') {
            throw ValidationException::withMessages(['session' => 'Only ready import sessions can be edited.']);
        }
        $rows = array_values(array_filter($session->suggestions, fn ($row) => ($row['edited_by'] ?? null) !== 'user' && (isset($arguments['ids']) ? in_array($row['id'], $arguments['ids'], true) : empty($row['party_id']) || empty($row['category_id']) || empty($row['wallet_id']))));
        $instructions = $context->user->importRules()->where('active', true)->where('kind', 'instruction')->orderBy('id')->pluck('instruction')->all();
        $instructions[] = $arguments['instruction'];
        $enriched = app(SuggestionEnricher::class)->enrich(array_map(fn ($row) => TransactionSuggestion::fromArray($row), $rows), $context->user, notes: $session->notes, instructions: $instructions, documentType: $session->document_type);
        $changes = [];
        foreach ($rows as $index => $row) {
            $result = ($enriched[$index] ?? TransactionSuggestion::fromArray($row))->toArray();
            $set = [];
            foreach (array_keys(ImportReviewService::rules()) as $key) {
                $originalValue = $row[$key] ?? null;
                $newValue = $result[$key] ?? null;
                if (in_array($key, ['amount', 'fee'], true) && $originalValue !== null && $newValue !== null) {
                    $originalValue = (float) $originalValue;
                    $newValue = (float) $newValue;
                }
                if (array_key_exists($key, $result) && $originalValue !== $newValue) {
                    $set[$key] = $result[$key];
                }
            }
            foreach (['party', 'category', 'wallet'] as $type) {
                if (($result[$type] ?? null) !== ($row[$type] ?? null) && ! empty($result[$type])) {
                    $set[$type] = $result[$type];
                }
            }
            if ($set !== []) {
                $changes[] = ['ids' => [$row['id']], 'set' => $this->resolveNames($set, $context)];
            }
        }
        $session = app(ImportReviewService::class)->update($session, $context->user, $changes, 'assistant', array_column($rows, null, 'id'));

        $original = array_column($rows, null, 'id');
        $changed = array_filter($session->suggestions, fn ($row) => isset($original[$row['id']]) && $row !== $original[$row['id']] && ($row['edited_by'] ?? null) === 'assistant');

        return ['changed' => count($changed), 'revision' => $session->revision];
    }
}
