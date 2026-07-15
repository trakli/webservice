<?php

namespace App\Services;

use App\Ai\BlockBuilder;
use App\Ai\BlockCollector;
use App\Events\ChatTurnEvent;
use App\Models\ChatMessage;
use Whilesmart\Agents\Enums\StreamEventType;
use Whilesmart\Agents\Facades\Agents;
use Whilesmart\Agents\ValueObjects\AgentStreamEvent;
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

        $result = Agents::stream(
            'trakli',
            $this->buildInput($userMessage),
            $context,
            fn (AgentStreamEvent $event) => $this->reportProgress($assistantMessage, $event),
        );

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
     * Append a human-readable step to the assistant message as the agent works,
     * so a client polling the message shows live progress instead of a spinner.
     * Consecutive identical labels collapse (the model emits several render_*
     * calls in a row that all mean "building the report").
     */
    private function reportProgress(?ChatMessage $assistantMessage, AgentStreamEvent $event): void
    {
        if ($assistantMessage === null || $event->type !== StreamEventType::ToolCall || $event->toolName === null) {
            return;
        }

        $label = $this->progressLabel($event->toolName);
        if ($label === null) {
            return;
        }

        $progress = $assistantMessage->progress ?? [];
        if (end($progress) === $label) {
            return;
        }

        $progress[] = $label;
        $assistantMessage->progress = $progress;
        $assistantMessage->save();

        // Push the step live; the saved progress remains the poll/reconnect
        // fallback. A broadcaster that is down must never break the turn.
        try {
            ChatTurnEvent::dispatch($assistantMessage->chat_session_id, $assistantMessage->id, 'progress', $label);
        } catch (\Throwable $e) {
            // Ignored: streaming is best-effort, the answer still completes.
        }
    }

    private function progressLabel(string $tool): ?string
    {
        if (str_starts_with($tool, 'render_') || $tool === 'open_canvas' || $tool === 'update_canvas') {
            return __('Putting your report together');
        }

        return match ($tool) {
            'smartql.query' => __('Looking through your records'),
            'get_stats' => __('Crunching the numbers'),
            'list_wallets', 'list_categories', 'list_parties' => __('Checking your accounts'),
            'get_exchange_rate', 'get_asset_price', 'convert_currency' => __('Fetching current rates'),
            'get_user_defaults' => __('Checking your settings'),
            'calculator' => __('Working out the figures'),
            'record_transaction', 'record_transfer', 'create_wallet',
            'create_category', 'create_party', 'categorize_transactions',
            'assign_transaction_categories',
            'attach_to_transaction' => __('Preparing your changes'),
            'import_document', 'extract_receipt' => __('Reading your document'),
            default => null,
        };
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

            $parts[] = 'The user attached the following file(s) to this message; '
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
