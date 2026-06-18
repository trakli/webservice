<?php

namespace App\Ai\Tools\Render;

use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Asks the user a question with selectable answers. Use this instead of guessing
 * when a needed detail is ambiguous (which wallet, which party, create-first or
 * not). Each option becomes a button whose text is sent back as the user's reply.
 */
class AskQuestionTool extends AbstractRenderTool
{
    public function name(): string
    {
        return 'ask_question';
    }

    public function description(): string
    {
        return 'Ask the user a question and offer a few selectable answers. Pass the question and '
            . 'options_json (a JSON array of short strings). Use when you need the user to choose '
            . '(e.g. "Did you mean Cash or Card?") rather than guessing.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('question', 'The question to ask the user.'),
            ParameterSpec::string('options_json', 'A JSON array of short answer strings, e.g. ["Cash","Card"].'),
        ];
    }

    /** @SuppressWarnings(PHPMD.UnusedFormalParameter) */
    public function handle(array $arguments, ToolContext $context): string|array
    {
        $prompt = trim((string) ($arguments['question'] ?? ''));
        if ($prompt === '') {
            return ['error' => 'A question is required.'];
        }

        $options = json_decode((string) ($arguments['options_json'] ?? ''), true);
        if (! is_array($options) || $options === []) {
            return ['error' => 'options_json must be a non-empty JSON array of strings.'];
        }

        $this->collector()->add($this->blocks()->question($prompt, $options));

        return 'Asked the user: ' . $prompt;
    }
}
