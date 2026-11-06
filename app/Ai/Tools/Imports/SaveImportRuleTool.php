<?php

namespace App\Ai\Tools\Imports;

use App\Models\ImportSession;
use App\Services\ImportReviewService;
use App\Services\ImportRuleValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Whilesmart\Agents\Enums\ToolPermission;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

class SaveImportRuleTool extends ImportReviewTool
{
    public function name(): string
    {
        return 'save_import_rule';
    }

    public function description(): string
    {
        return 'Save a reusable import rule and apply it to this draft review immediately.';
    }

    public function permission(): ToolPermission
    {
        return ToolPermission::WRITE;
    }

    public function parameters(): array
    {
        return [...parent::parameters(),
            ParameterSpec::enum('kind', 'Rule kind', ['party_alias', 'category_hint', 'wallet_hint', 'skip', 'instruction']),
            ParameterSpec::enum('match_field', 'Statement text field', ['description', 'counterparty', 'any'], false),
            ParameterSpec::enum('match_mode', 'Matching mode', ['contains', 'exact', 'starts_with'], false),
            ParameterSpec::string('pattern', 'Text to match', false),
            ParameterSpec::enum('target_type', 'Target resource', ['party', 'category', 'wallet'], false),
            ParameterSpec::number('target_id', 'Existing target id', false),
            ParameterSpec::string('instruction', 'Classification instruction', false),
            ParameterSpec::boolean('active', 'Enable this rule', false),
        ];
    }

    protected function run(array $arguments, ToolContext $context, ImportSession $session): array
    {
        return DB::transaction(function () use ($arguments, $context, $session) {
            $session = ImportSession::ownedBy($context->user)->lockForUpdate()->findOrFail($session->id);
            if ($session->status !== 'ready') {
                throw ValidationException::withMessages(['session' => 'Only ready import sessions can be edited.']);
            }
            $data = app(ImportRuleValidator::class)->validate($arguments, $context->user);
            $rule = $context->user->importRules()->create([...$data, 'active' => $data['active'] ?? true, 'source_session_id' => $session->id]);
            if (! $rule->active) {
                return ['rule_id' => $rule->id, 'changed' => 0, 'revision' => $session->revision];
            }
            if ($data['kind'] === 'instruction') {
                $result = app(RematchImportRowsTool::class)->handle(['session_id' => $session->id, 'ids' => array_column($session->suggestions, 'id'), 'instruction' => $data['instruction']], $context);
                if (isset($result['error'])) {
                    throw ValidationException::withMessages(['instruction' => $result['error']]);
                }

                return ['rule_id' => $rule->id, ...$result];
            }
            $ids = $rule->active ? $this->matchingIds($session->suggestions, ['field' => $data['match_field'], 'mode' => $data['match_mode'], 'pattern' => $data['pattern']]) : [];
            $set = $data['kind'] === 'skip' ? ['skip' => true] : [$data['target_type'].'_id' => $data['target_id']];
            $session = app(ImportReviewService::class)->update($session, $context->user, [[
                'ids' => $ids, 'set' => $set,
                'matched_rule' => ['rule_id' => $rule->id, 'kind' => $data['kind'], 'pattern' => $data['pattern'], 'target_name' => $rule->target()?->name],
            ]], 'assistant');

            return ['rule_id' => $rule->id, 'changed' => count($ids), 'revision' => $session->revision];
        });
    }
}
