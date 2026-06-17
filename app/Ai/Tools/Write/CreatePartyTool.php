<?php

namespace App\Ai\Tools\Write;

use App\Models\AgentProposedAction;
use InvalidArgumentException;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Proposes creating a party (a person or organization the user transacts with).
 */
class CreatePartyTool extends AbstractWriteTool
{
    private const TYPES = [
        'individual', 'organization', 'business', 'partnership',
        'non_profit', 'government_agency', 'educational_institution', 'healthcare_provider',
    ];

    public function name(): string
    {
        return 'create_party';
    }

    public function actionType(): string
    {
        return 'party.create';
    }

    public function description(): string
    {
        return 'Propose creating a party (a person or organization). Needs a name; type is optional.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('name', 'The party name, e.g. "Whole Foods".'),
            ParameterSpec::enum('type', 'The kind of party.', self::TYPES, required: false),
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
            throw new InvalidArgumentException('A party name is required.');
        }

        $type = $arguments['type'] ?? null;
        if ($type !== null && ! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('That party type is not recognised.');
        }

        return array_filter([
            'name' => $name,
            'type' => $type,
            'description' => $arguments['description'] ?? null,
        ], fn ($value) => $value !== null);
    }

    protected function summarize(array $payload, ToolContext $context): string
    {
        return "Create a party \"{$payload['name']}\".";
    }
}
