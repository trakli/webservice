<?php

namespace App\Ai\Tools\Write;

use App\Models\AgentProposedAction;
use InvalidArgumentException;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Proposes creating a category. Low risk (no money moves), but still confirmed.
 */
class CreateCategoryTool extends AbstractWriteTool
{
    public function name(): string
    {
        return 'create_category';
    }

    public function actionType(): string
    {
        return 'category.create';
    }

    public function description(): string
    {
        return 'Propose creating a category. Needs a name and a type (income or expense).';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('name', 'The category name, e.g. "Groceries".'),
            ParameterSpec::enum('type', 'Whether this categorizes income or expense.', ['income', 'expense']),
            ParameterSpec::string('description', 'Optional description.', required: false),
        ];
    }

    protected function risk(): string
    {
        return AgentProposedAction::RISK_LOW;
    }

    protected function buildPayload(array $arguments, ToolContext $context): array
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('A category name is required.');
        }

        $type = $arguments['type'] ?? null;
        if (! in_array($type, ['income', 'expense'], true)) {
            throw new InvalidArgumentException('Type must be income or expense.');
        }

        return array_filter([
            'name' => $name,
            'type' => $type,
            'description' => $arguments['description'] ?? null,
        ], fn ($value) => $value !== null);
    }

    protected function summarize(array $payload, ToolContext $context): string
    {
        return "Create a {$payload['type']} category \"{$payload['name']}\".";
    }
}
