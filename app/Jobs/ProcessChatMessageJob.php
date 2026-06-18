<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Services\AgentRunner;
use App\Services\AiRouter;
use App\Services\AiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessChatMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;

    public int $tries = 2;

    public function __construct(public ChatMessage $assistantMessage)
    {
    }

    /** @SuppressWarnings(PHPMD.NPathComplexity) */
    public function handle(AiService $aiService, AiRouter $router, AgentRunner $agentRunner): void
    {
        $this->assistantMessage->update(['status' => ChatMessage::STATUS_PROCESSING]);

        $userMessage = $this->assistantMessage->session->messages()
            ->reorder('id', 'desc')
            ->where('role', ChatMessage::ROLE_USER)
            ->where('id', '<', $this->assistantMessage->id)
            ->first();

        if ($userMessage === null) {
            $this->markFailed(__('No question found for this assistant message.'));

            return;
        }

        // An attached file always means "act on this document": route straight to
        // the agent so the import/extract tools run, regardless of the caption.
        // Otherwise classify with the recent conversation so a follow-up that
        // continues an in-progress action (e.g. answering "which wallet?") isn't
        // judged in isolation and misrouted.
        $route = $userMessage->files()->exists()
            ? AiRouter::ROUTE_AGENT
            : $router->classify($userMessage->content, $this->recentConversation($userMessage));

        if ($route === AiRouter::ROUTE_GENERAL) {
            $this->answerGeneral($router, $userMessage->content);
            $this->maybeGenerateTitle($router, $userMessage->content);

            return;
        }

        if ($route === AiRouter::ROUTE_AGENT) {
            $this->answerAgent($agentRunner, $router, $userMessage);
            $this->maybeGenerateTitle($router, $userMessage->content);

            return;
        }

        $response = $aiService->ask(
            question: $userMessage->content,
            userId: (int) $userMessage->user_id,
            execute: true,
            formatHint: $userMessage->format_hint,
            generateResponse: true,
            language: $userMessage->language ?? app()->getLocale(),
            role: $userMessage->user?->hasRole('admin') ? 'admin' : null,
        );

        // Infra/auth failure (SmartQL down, expired key, timeout): not the user's
        // fault, so don't tell them to rephrase; say the service is unavailable.
        if (! ($response['success'] ?? false)) {
            $this->answerUnavailable();
            $this->maybeGenerateTitle($router, $userMessage->content);

            return;
        }

        // Genuine empty result: a rephrase suggestion is the right nudge.
        $rows = $response['data']['rows'] ?? null;
        if (is_array($rows) && $rows === []) {
            $this->answerGeneral($router, $userMessage->content, __('The data query returned no results.'));
            $this->maybeGenerateTitle($router, $userMessage->content);

            return;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $content = is_string($data['human_response'] ?? null)
            ? $data['human_response']
            : (is_string($data['explanation'] ?? null) ? $data['explanation'] : null);

        $this->assistantMessage->update([
            'status' => ChatMessage::STATUS_COMPLETED,
            'content' => $content,
            'result' => array_merge($data, ['source' => 'smartql']),
            'completed_at' => now(),
        ]);

        $this->maybeGenerateTitle($router, $userMessage->content);
    }

    /**
     * The recent turns of this session before the current message, as a compact
     * role-labelled transcript, so the router can judge a message in context.
     */
    private function recentConversation(ChatMessage $userMessage): string
    {
        return $userMessage->session->messages()
            ->where('id', '<', $userMessage->id)
            ->whereIn('role', [ChatMessage::ROLE_USER, ChatMessage::ROLE_ASSISTANT])
            ->orderBy('id')
            ->get(['role', 'content'])
            ->filter(fn (ChatMessage $m) => trim((string) $m->content) !== '')
            ->take(-10)
            ->map(fn (ChatMessage $m) => ($m->role === ChatMessage::ROLE_USER ? 'User' : 'Assistant')
                . ': ' . trim((string) $m->content))
            ->implode("\n");
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ProcessChatMessageJob failed', [
            'message_id' => $this->assistantMessage->id,
            'message' => $exception?->getMessage(),
        ]);

        $this->markFailed(
            $exception?->getMessage() ?? __('AI service is currently unavailable. Please try again later.')
        );
    }

    private function answerUnavailable(): void
    {
        $this->assistantMessage->update([
            'status' => ChatMessage::STATUS_COMPLETED,
            'content' => __('The data service is temporarily unavailable. Please try again shortly.'),
            'result' => ['source' => 'unavailable', 'format_type' => 'raw', 'rows' => []],
            'completed_at' => now(),
        ]);
    }

    private function answerAgent(AgentRunner $agentRunner, AiRouter $router, ChatMessage $userMessage): void
    {
        $result = $agentRunner->run($userMessage, $this->assistantMessage);

        if (! ($result['ok'] ?? false)) {
            // Fall back to a conversational answer so the turn still resolves.
            $this->answerGeneral($router, $userMessage->content, $result['error'] ?? null);

            return;
        }

        $this->assistantMessage->update([
            'status' => ChatMessage::STATUS_COMPLETED,
            'content' => $result['text'] ?? '',
            'result' => [
                'source' => 'agent',
                'blocks' => $result['blocks'] ?? [],
                'tool_calls' => $result['tool_calls'] ?? [],
                'usage' => $result['usage'] ?? [],
            ],
            'completed_at' => now(),
        ]);
    }

    private function answerGeneral(AiRouter $router, string $question, ?string $hint = null): void
    {
        $answer = $router->answerGeneral($question, $hint);

        if (! $answer['success']) {
            $this->markFailed($answer['error']);

            return;
        }

        $source = $hint === null ? 'prism' : 'prism_fallback';

        $this->assistantMessage->update([
            'status' => ChatMessage::STATUS_COMPLETED,
            'content' => $answer['text'],
            'result' => [
                'source' => $source,
                'format_type' => 'raw',
                'rows' => [],
            ],
            'completed_at' => now(),
        ]);
    }

    private function maybeGenerateTitle(AiRouter $router, string $firstQuestion): void
    {
        $session = $this->assistantMessage->session;

        if ($session->title !== null) {
            return;
        }

        $completedAssistants = $session->messages()
            ->where('role', ChatMessage::ROLE_ASSISTANT)
            ->where('status', ChatMessage::STATUS_COMPLETED)
            ->count();

        if ($completedAssistants !== 1) {
            return;
        }

        $session->update([
            'title' => $router->generateTitle($firstQuestion) ?? Str::limit($firstQuestion, 60),
        ]);
    }

    private function markFailed(string $error): void
    {
        $this->assistantMessage->update([
            'status' => ChatMessage::STATUS_FAILED,
            'error' => $error,
            'completed_at' => now(),
        ]);
    }
}
