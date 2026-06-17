<?php

namespace App\Ai\Tools\Read;

use App\Services\AiService;
use Whilesmart\Agents\Enums\ToolPermission;
use Whilesmart\Agents\Tools\AbstractTool;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Answers natural-language questions about the acting user's own finances by
 * delegating to the SmartQL data layer. Returns the rows for the agent to
 * reason over; it does not ask SmartQL to phrase the answer (the agent does).
 */
class SmartqlQueryTool extends AbstractTool
{
    public function __construct(private AiService $aiService)
    {
    }

    public function name(): string
    {
        return 'smartql.query';
    }

    public function description(): string
    {
        return 'Query the user\'s own financial records (transactions, wallets, categories, '
            . 'parties, transfers, balances) with a natural-language question. Use this for any '
            . 'question about what the user spent, earned, owns or owes. Returns matching rows.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('question', 'The data question in natural language, e.g. "total spent on groceries last month".'),
        ];
    }

    public function permission(): ToolPermission
    {
        return ToolPermission::READ;
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $user = $context->user;

        if ($user === null) {
            return ['error' => 'No authenticated user in context.'];
        }

        $question = trim((string) ($arguments['question'] ?? ''));

        if ($question === '') {
            return ['error' => 'A question is required.'];
        }

        $result = $this->aiService->ask(
            question: $question,
            userId: (int) $user->getAuthIdentifier(),
            execute: true,
            formatHint: null,
            generateResponse: false,
            language: $context->locale ?? app()->getLocale(),
            role: method_exists($user, 'hasRole') && $user->hasRole('admin') ? 'admin' : null,
        );

        if (! ($result['success'] ?? false)) {
            return ['error' => $result['error'] ?? 'The data query failed.'];
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        return [
            'rows' => $data['rows'] ?? [],
            'explanation' => $data['explanation'] ?? null,
            'format_type' => $data['format_type'] ?? null,
        ];
    }
}
