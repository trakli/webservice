<?php

namespace App\Ai\Tools\Write;

use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

class ToggleImportRuleTool extends AbstractWriteTool
{
    public function name(): string
    {
        return 'toggle_import_rule';
    }

    public function actionType(): string
    {
        return 'import_rule.toggle';
    }

    public function description(): string
    {
        return 'Propose enabling or disabling one of the user’s saved import rules.';
    }

    public function parameters(): array
    {
        return [ParameterSpec::number('rule_id', 'Saved import rule ID'), ParameterSpec::boolean('active', 'Whether to apply the rule on future imports')];
    }

    protected function buildPayload(array $arguments, ToolContext $context): array
    {
        $validator = Validator::make($arguments, ['rule_id' => 'required|integer|min:1', 'active' => 'required|boolean']);
        if ($validator->fails() || ! $context->user->importRules()->whereKey($arguments['rule_id'] ?? 0)->exists()) {
            throw new InvalidArgumentException('Select one of your import rules and whether it should be active.');
        }

        return $validator->validated();
    }

    protected function summarize(array $payload, ToolContext $context): string
    {
        return ($payload['active'] ? 'Enable' : 'Disable') . ' import rule #' . $payload['rule_id'] . '.';
    }
}
