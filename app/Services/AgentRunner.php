<?php

namespace App\Services;

use App\Ai\BlockBuilder;
use App\Ai\BlockCollector;
use App\Models\ChatMessage;
use Whilesmart\Agents\Facades\Agents;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Runs the Trakli agent harness for a chat turn and maps its result into the
 * widget-block shape the chat message stores. The single server-side brain
 * behind every AI surface.
 */
class AgentRunner
{
    public function __construct(protected BlockBuilder $blocks)
    {
    }

    /**
     * @return array{ok: bool, text?: string, blocks?: array<int, array<string, mixed>>,
     *               tool_calls?: array<int, mixed>, usage?: array<string, int>, error?: string}
     */
    public function run(ChatMessage $userMessage, ?ChatMessage $assistantMessage = null): array
    {
        $user = $userMessage->user;

        if ($user === null) {
            return ['ok' => false, 'error' => __('No user found for this message.')];
        }

        $context = ToolContext::forUser(
            $user,
            $userMessage->language ?? app()->getLocale(),
            [
                'chat_session_id' => $userMessage->chat_session_id,
                'chat_message_id' => $assistantMessage?->id,
            ],
        );

        // Bind a fresh collector for this run so render tools accumulate into it.
        $collector = new BlockCollector();
        app()->instance(BlockCollector::class, $collector);

        $result = Agents::run('trakli', $this->buildInput($userMessage), $context);

        if (! $result->ok) {
            return ['ok' => false, 'error' => $result->error ?? __('The assistant could not complete the request.')];
        }

        $blocks = [];
        $narration = trim($result->text);
        if ($narration !== '') {
            $blocks[] = $this->blocks->markdown($narration);
        }

        $collected = $collector->all();
        if ($collector->canvasTitle() !== null && $collected !== []) {
            // Canvas mode: the rendered pieces form one document shown in the
            // side canvas. The chat keeps only the agent's short lead-in.
            $blocks[] = $this->blocks->canvas($collector->canvasTitle(), $collected);
        } else {
            // Inline mode: narration first, then widgets in call order.
            foreach ($collected as $block) {
                $blocks[] = $block;
            }
        }

        return [
            'ok' => true,
            'text' => $result->text,
            'blocks' => $blocks,
            'tool_calls' => $result->toolCalls,
            'usage' => $result->usage,
        ];
    }

    /**
     * The agent input is just a string, so we fold the recent conversation and
     * any attachment on the current turn into it: prior turns give continuity
     * (so a follow-up like "here it is" isn't read in isolation), and the
     * attachment notice tells the agent a file is present so it reaches for the
     * import/extract tools (which resolve the actual file from the chat).
     */
    private function buildInput(ChatMessage $userMessage): string
    {
        $parts = [];

        $history = $userMessage->session->messages()
            ->where('id', '<', $userMessage->id)
            ->whereIn('role', [ChatMessage::ROLE_USER, ChatMessage::ROLE_ASSISTANT])
            ->orderBy('id')
            ->get(['role', 'content'])
            ->filter(fn (ChatMessage $m) => trim((string) $m->content) !== '')
            ->take(-10);

        if ($history->isNotEmpty()) {
            $lines = $history->map(function (ChatMessage $m): string {
                $speaker = $m->role === ChatMessage::ROLE_USER ? 'User' : 'Assistant';

                return "{$speaker}: " . trim((string) $m->content);
            })->implode("\n");

            $parts[] = "Conversation so far:\n{$lines}";
        }

        $files = $userMessage->files;
        if ($files->isNotEmpty()) {
            $list = $files->map(function ($file): string {
                $name = basename((string) $file->path);
                $documentType = $file->metadata['document_type'] ?? null;
                $kind = $documentType ? " ({$documentType})" : '';

                return "- {$name}{$kind}";
            })->implode("\n");

            $parts[] = "The user attached the following file(s) to this message; "
                . "use the import or receipt tools to process them:\n{$list}";
        }

        $canvasTitle = $this->latestCanvasTitle($userMessage);
        if ($canvasTitle !== null) {
            $parts[] = "A canvas report titled \"{$canvasTitle}\" exists from an earlier turn; "
                . 'to change or add to it call update_canvas (not open_canvas).';
        }

        $current = trim((string) $userMessage->content);
        $parts[] = $current === '' ? '(no text; see attached file)' : "User: {$current}";

        return implode("\n\n", $parts);
    }

    /**
     * The title of the most recent canvas in this session, if any, so the agent
     * knows a prior report exists and reaches for the edit tool.
     */
    private function latestCanvasTitle(ChatMessage $userMessage): ?string
    {
        $messages = $userMessage->session->messages()
            ->where('id', '<', $userMessage->id)
            ->where('role', ChatMessage::ROLE_ASSISTANT)
            ->orderBy('id', 'desc')
            ->get(['id', 'result']);

        foreach ($messages as $message) {
            $blocks = $message->result['blocks'] ?? [];
            if (! is_array($blocks)) {
                continue;
            }
            foreach ($blocks as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'canvas') {
                    return (string) ($block['title'] ?? 'Report');
                }
            }
        }

        return null;
    }
}
