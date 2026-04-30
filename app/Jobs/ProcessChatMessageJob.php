<?php

namespace App\Jobs;

use App\Models\ChatMessage;
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

    public function handle(AiService $aiService, AiRouter $router): void
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

        $route = $router->classify($userMessage->content);

        if ($route === AiRouter::ROUTE_GENERAL) {
            $this->answerGeneral($router, $userMessage->content);
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
        );

        if ($this->isSoftFailure($response)) {
            $this->answerGeneral(
                $router,
                $userMessage->content,
                $response['error'] ?? __('The data query returned no results.')
            );
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

    private function isSoftFailure(array $response): bool
    {
        if (! ($response['success'] ?? false)) {
            return true;
        }

        $rows = $response['data']['rows'] ?? null;

        return is_array($rows) && $rows === [];
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
