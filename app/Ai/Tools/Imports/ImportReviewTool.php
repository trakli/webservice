<?php

namespace App\Ai\Tools\Imports;

use App\Models\ImportRule;
use App\Models\ImportSession;
use App\Services\ImportRuleMatcher;
use App\Types\TransactionSuggestion;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Whilesmart\Agents\Tools\AbstractTool;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

abstract class ImportReviewTool extends AbstractTool
{
    public function parameters(): array
    {
        return [ParameterSpec::number('session_id', 'Import session, defaults to the current import', false)];
    }

    final public function handle(array $arguments, ToolContext $context): string|array
    {
        try {
            Validator::make($arguments, ['session_id' => 'sometimes|integer|min:1'])->validate();
            $session = $context->user?->importSessions()->find($arguments['session_id'] ?? $context->get('import_session_id'));
            if (! $session) {
                return ['error' => 'Import session not found.'];
            }
            $session->ensureSuggestionIds();

            return $this->run($arguments, $context, $session);
        } catch (ValidationException $exception) {
            return ['error' => $exception->getMessage(), 'errors' => $exception->errors()];
        }
    }

    abstract protected function run(array $arguments, ToolContext $context, ImportSession $session): array;

    protected function matchingIds(array $rows, array $match): array
    {
        Validator::make($match, [
            'field' => 'required|in:description,counterparty,any',
            'mode' => 'required|in:contains,exact,starts_with',
            'pattern' => 'required|string|max:255',
        ])->validate();

        return array_values(array_column(array_filter($rows, fn ($row) => app(ImportRuleMatcher::class)->matches([
            'match_field' => $match['field'], 'match_mode' => $match['mode'], 'pattern' => $match['pattern'],
        ], TransactionSuggestion::fromArray([...$row, 'party' => $row['counterparty'] ?? $row['party'] ?? null]))), 'id'));
    }

    protected function resolveNames(array $set, ToolContext $context): array
    {
        foreach (['party', 'category', 'wallet'] as $type) {
            $key = $type.'_id';
            $name = $set[$type] ?? (is_string($set[$key] ?? null) && ! ctype_digit($set[$key]) ? $set[$key] : null);
            unset($set[$type]);
            if ($name === null) {
                continue;
            }
            Validator::make(['name' => $name], ['name' => 'required|string|max:255'])->validate();
            $targets = ImportRule::targetClass($type)::ownedBy($context->user)->orderBy('name')->get();
            $matches = $targets->filter(fn ($target) => mb_strtolower($target->name) === mb_strtolower($name));
            if ($matches->count() !== 1) {
                $close = $targets->sortBy(fn ($target) => levenshtein(mb_strtolower($name), mb_strtolower($target->name)))->take(5)->pluck('name')->implode(', ');
                throw ValidationException::withMessages([$key => "No unique exact {$type} named {$name}. Existing names: {$close}"]);
            }
            $set[$key] = $matches->first()->id;
        }

        return $set;
    }
}
