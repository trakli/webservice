<?php

namespace App\Http\Controllers\API\v1;

use App\Ai\Export\DocumentExporterManager;
use App\Ai\Tools\Write\AbstractWriteTool;
use App\Http\Controllers\API\ApiController;
use App\Jobs\ProcessChatMessageJob;
use App\Models\AgentProposedAction;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\AiService;
use App\Services\FileService;
use App\Services\ProposedActionExecutor;
use App\Services\TransactionWriter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
use Whilesmart\Activities\Models\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Whilesmart\Agents\Registries\ToolRegistry;
use Whilesmart\Agents\ValueObjects\ToolContext;

#[OA\Tag(name: 'AI', description: 'AI-powered financial insights')]
class AiController extends ApiController
{
    public function __construct(
        protected AiService $aiService
    ) {
    }

    #[OA\Get(
        path: '/ai/chats',
        summary: "List the authenticated user's chat sessions",
        tags: ['AI'],
        responses: [new OA\Response(response: 200, description: 'List of chat sessions')]
    )]
    public function index(Request $request): JsonResponse
    {
        $sessions = ChatSession::query()
            ->ownedBy($request->user())
            ->orderByDesc('updated_at')
            ->paginate(20);

        return $this->success($sessions);
    }

    #[OA\Post(
        path: '/ai/chats',
        summary: 'Start a new chat session with a first question',
        tags: ['AI'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['message'],
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'How much did I spend last month?'),
                    new OA\Property(property: 'title', type: 'string', nullable: true),
                    new OA\Property(
                        property: 'format_hint',
                        type: 'string',
                        enum: ['scalar', 'pair', 'record', 'list', 'pair_list', 'table', 'raw']
                    ),
                ]
            )
        ),
        responses: [new OA\Response(response: 202, description: 'Session created and message queued')]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
            'title' => 'nullable|string|max:255',
            'format_hint' => 'nullable|string|in:scalar,pair,record,list,pair_list,table,raw',
            'defer_processing' => 'sometimes|boolean',
        ]);

        $user = $request->user();

        $session = new ChatSession([
            'title' => $validated['title'] ?? null,
        ]);
        $session->owner()->associate($user);
        $session->save();

        $this->dispatchTurn($session, $user, $validated, $request);

        return $this->success(
            $session->fresh()->load('messages'),
            __('Chat session created.'),
            Response::HTTP_ACCEPTED
        );
    }

    #[OA\Get(
        path: '/ai/chats/{chat}',
        summary: 'Get a chat session with all its messages',
        tags: ['AI'],
        parameters: [new OA\Parameter(name: 'chat', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Chat session with messages'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Request $request, ChatSession $chat): JsonResponse
    {
        $this->authorizeOwnership($request->user(), $chat);

        return $this->success($chat->load('messages'));
    }

    #[OA\Post(
        path: '/ai/chats/{chat}/messages',
        summary: 'Add a follow-up message to an existing chat session',
        tags: ['AI'],
        parameters: [new OA\Parameter(name: 'chat', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['message'],
                properties: [
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(
                        property: 'format_hint',
                        type: 'string',
                        enum: ['scalar', 'pair', 'record', 'list', 'pair_list', 'table', 'raw']
                    ),
                ]
            )
        ),
        responses: [new OA\Response(response: 202, description: 'Message queued')]
    )]
    public function storeMessage(Request $request, ChatSession $chat): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
            'format_hint' => 'nullable|string|in:scalar,pair,record,list,pair_list,table,raw',
            'defer_processing' => 'sometimes|boolean',
        ]);

        $this->authorizeOwnership($request->user(), $chat);
        $user = $request->user();

        [$userMessage, $assistantMessage] = $this->dispatchTurn($chat, $user, $validated, $request);
        $chat->touch();

        return $this->success(
            ['user' => $userMessage, 'assistant' => $assistantMessage],
            __('Message queued.'),
            Response::HTTP_ACCEPTED
        );
    }

    #[OA\Delete(
        path: '/ai/chats/{chat}',
        summary: 'Delete a chat session',
        tags: ['AI'],
        parameters: [new OA\Parameter(name: 'chat', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Request $request, ChatSession $chat): JsonResponse
    {
        $this->authorizeOwnership($request->user(), $chat);
        $chat->delete();

        return $this->success(null, __('Chat session deleted.'));
    }

    #[OA\Get(
        path: '/ai/health',
        summary: 'Check AI service health',
        tags: ['AI'],
        responses: [new OA\Response(response: 200, description: 'Service status')]
    )]
    public function health(): JsonResponse
    {
        return response()->json([
            'available' => $this->aiService->healthCheck(),
        ]);
    }

    #[OA\Post(
        path: '/ai/chats/{chat}/actions/{action}/confirm',
        summary: 'Confirm and execute an agent-proposed action',
        tags: ['AI'],
        parameters: [
            new OA\Parameter(name: 'chat', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'action', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Action executed'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 409, description: 'Action no longer confirmable'),
        ]
    )]
    public function confirmAction(
        Request $request,
        ChatSession $chat,
        AgentProposedAction $action,
        ProposedActionExecutor $executor
    ): JsonResponse {
        $user = $request->user();
        $this->authorizeAction($user, $chat, $action);

        // Idempotent replay: a retried confirm returns the already-created resource.
        if ($action->status === AgentProposedAction::STATUS_EXECUTED) {
            return $this->success(
                ['action' => $action, 'resource' => $action->executedResource()->first()],
                __('Action already completed.')
            );
        }

        if (! in_array($action->status, [AgentProposedAction::STATUS_PROPOSED, AgentProposedAction::STATUS_CONFIRMED], true)) {
            return $this->failure(__('This action can no longer be confirmed.'), Response::HTTP_CONFLICT);
        }

        // The user may edit the proposed fields before confirming. Only keys that
        // already exist in the proposal may be overridden (never inject user_id),
        // and the merged payload is re-validated through the same ownership rules.
        $overrides = $request->input('overrides');
        // Drop blank values so an untouched field (e.g. an empty "When") is
        // treated as "not provided" rather than overwriting the proposal with
        // an empty string (which would cast to the Unix epoch).
        if (is_array($overrides)) {
            $overrides = array_filter($overrides, fn ($value) => $value !== '' && $value !== null);
        }
        $described = null;
        if (is_array($overrides) && $overrides !== []) {
            $allowed = array_flip($this->allowedOverrideKeys($action->action_type));
            $merged = array_merge($action->payload, array_intersect_key($overrides, $allowed));

            try {
                $this->revalidateOverride($user, $action->action_type, $merged);
            } catch (HttpException $e) {
                return $this->failure($e->getMessage(), $e->getStatusCode());
            }

            $action->update(['payload' => $merged]);
            $action->refresh();

            // Regenerate the human summary/fields so the confirmed card reflects
            // the edits, instead of the text frozen when it was first proposed.
            $described = $this->describeProposal($action, $user);
            if ($described !== null) {
                $action->update(['summary' => $described['summary']]);
            }
        }

        try {
            $resource = $executor->execute($action);
        } catch (Throwable $e) {
            $action->update([
                'status' => AgentProposedAction::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);

            return $this->failure(__('Failed to perform the action.'), Response::HTTP_INTERNAL_SERVER_ERROR, [$e->getMessage()]);
        }

        $action->update([
            'status' => AgentProposedAction::STATUS_EXECUTED,
            'confirmed_at' => now(),
            'executed_at' => now(),
            'executed_resource_type' => $resource->getMorphClass(),
            'executed_resource_id' => $resource->getKey(),
        ]);

        $this->recordActivity($user, $chat, $action, $resource);
        $this->markActionBlockStatus($action, AgentProposedAction::STATUS_EXECUTED, $described);

        return $this->success(['action' => $action->fresh(), 'resource' => $resource], __('Action completed.'));
    }

    #[OA\Post(
        path: '/ai/chats/{chat}/actions/{action}/reject',
        summary: 'Reject an agent-proposed action',
        tags: ['AI'],
        parameters: [
            new OA\Parameter(name: 'chat', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'action', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Action dismissed'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 409, description: 'Action already executed'),
        ]
    )]
    public function rejectAction(Request $request, ChatSession $chat, AgentProposedAction $action): JsonResponse
    {
        $user = $request->user();
        $this->authorizeAction($user, $chat, $action);

        if ($action->status === AgentProposedAction::STATUS_EXECUTED) {
            return $this->failure(__('This action was already completed.'), Response::HTTP_CONFLICT);
        }

        $action->update(['status' => AgentProposedAction::STATUS_REJECTED]);
        $this->markActionBlockStatus($action, AgentProposedAction::STATUS_REJECTED);

        return $this->success(['action' => $action->fresh()], __('Action dismissed.'));
    }

    #[OA\Post(
        path: '/ai/chats/{chat}/messages/{message}/files',
        summary: 'Attach files to a chat message (for receipts, statements, etc.)',
        tags: ['AI'],
        parameters: [
            new OA\Parameter(name: 'chat', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'message', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Files attached'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function uploadFiles(Request $request, ChatSession $chat, ChatMessage $message): JsonResponse
    {
        $user = $request->user();
        $this->authorizeOwnership($user, $chat);

        if ((int) $message->chat_session_id !== (int) $chat->id) {
            abort(Response::HTTP_NOT_FOUND, 'Message not found.');
        }

        $request->validate([
            'files' => 'required|array',
            'files.*' => 'file|mimes:' . FileService::ALLOWED_EXTENSIONS . '|max:' . FileService::MAX_KILOBYTES,
            'document_type' => 'nullable|string|max:50',
        ]);

        FileService::uploadFiles($message, $request, 'files', 'chat_attachments', $request->input('document_type'));

        // The turn's assistant reply was held until the files landed; release it
        // now so the agent processes the message with the attachment in place.
        $assistantMessage = $chat->messages()
            ->where('role', ChatMessage::ROLE_ASSISTANT)
            ->where('id', '>', $message->id)
            ->orderBy('id')
            ->first();

        if ($assistantMessage !== null && $assistantMessage->status === ChatMessage::STATUS_PENDING) {
            ProcessChatMessageJob::dispatch($assistantMessage);
        }

        return $this->success($message->fresh()->load('files'), __('Files attached.'));
    }

    #[OA\Get(
        path: '/ai/chats/{chat}/messages/{message}/export',
        summary: 'Export a message canvas document in a given format',
        tags: ['AI'],
        parameters: [
            new OA\Parameter(name: 'chat', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'message', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'format', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'md')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The exported document as a file download'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Unsupported format'),
        ]
    )]
    public function exportCanvas(
        Request $request,
        ChatSession $chat,
        ChatMessage $message,
        DocumentExporterManager $exporters
    ): Response {
        $user = $request->user();
        $this->authorizeOwnership($user, $chat);

        if ((int) $message->chat_session_id !== (int) $chat->id) {
            abort(Response::HTTP_NOT_FOUND, 'Message not found.');
        }

        $format = (string) $request->query('format', 'md');
        if (! $exporters->has($format)) {
            return $this->failure(__('Unsupported export format.'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $blocks = $message->result['blocks'] ?? [];
        $canvas = collect($blocks)->first(fn ($b) => is_array($b) && ($b['type'] ?? null) === 'canvas');

        if ($canvas === null) {
            return $this->failure(__('This message has no document to export.'), Response::HTTP_NOT_FOUND);
        }

        $exporter = $exporters->for($format);
        $title = (string) ($canvas['title'] ?? 'Document');
        $content = $exporter->export(is_array($canvas['blocks'] ?? null) ? $canvas['blocks'] : [], $title);
        $filename = (Str::slug($title) ?: 'document') . '.' . $exporter->extension();

        return response($content, Response::HTTP_OK, [
            'Content-Type' => $exporter->mimeType(),
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function authorizeOwnership(User $user, ChatSession $session): void
    {
        if (! $session->owner?->is($user)) {
            abort(Response::HTTP_NOT_FOUND, 'Chat session not found.');
        }
    }

    private function authorizeAction(User $user, ChatSession $chat, AgentProposedAction $action): void
    {
        $this->authorizeOwnership($user, $chat);

        if ((int) $action->chat_session_id !== (int) $chat->id || ! $action->owner?->is($user)) {
            abort(Response::HTTP_NOT_FOUND, 'Action not found.');
        }
    }

    /**
     * Re-validate an edited (overridden) payload before executing. Ownership is
     * the security-critical check; field rules mirror the tool's own validation.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws HttpException
     */
    /**
     * Keys a user is allowed to override on confirm, per action type. Anything
     * else (notably user_id) is dropped before merging.
     *
     * @return array<int, string>
     */
    private function allowedOverrideKeys(string $actionType): array
    {
        return match ($actionType) {
            'transaction.create' => ['amount', 'type', 'wallet_id', 'party_id', 'description', 'datetime', 'categories'],
            'transaction.categorize' => ['categories'],
            'transfer.create' => ['amount', 'from_wallet_id', 'to_wallet_id', 'exchange_rate', 'datetime'],
            'wallet.create' => ['name', 'type', 'currency', 'description'],
            'category.create' => ['name', 'type', 'description'],
            'party.create' => ['name', 'type', 'description'],
            default => [],
        };
    }

    private function revalidateOverride(User $user, string $actionType, array $payload): void
    {
        if (in_array($actionType, ['transaction.create', 'transaction.categorize'], true)) {
            $this->revalidateTransactionOverride($user, $payload);
        }

        if ($actionType === 'transfer.create') {
            $this->revalidateTransferOverride($user, $payload);
        }
    }

    private function revalidateTransactionOverride(User $user, array $payload): void
    {
        app(TransactionWriter::class)->validateOwnership($user, $payload, $payload['categories'] ?? []);

        if (isset($payload['amount']) && (float) $payload['amount'] <= 0) {
            throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, 'Amount must be greater than zero.');
        }
        if (isset($payload['type']) && ! in_array($payload['type'], ['income', 'expense'], true)) {
            throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, 'Type must be income or expense.');
        }
    }

    private function revalidateTransferOverride(User $user, array $payload): void
    {
        foreach (['from_wallet_id', 'to_wallet_id'] as $key) {
            if (! empty($payload[$key]) && ! $user->wallets()->whereKey($payload[$key])->exists()) {
                throw new HttpException(Response::HTTP_FORBIDDEN, 'The selected wallet does not belong to you.');
            }
        }
        if (! empty($payload['from_wallet_id']) && $payload['from_wallet_id'] === ($payload['to_wallet_id'] ?? null)) {
            throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, 'The source and destination wallets must be different.');
        }
        if (isset($payload['amount']) && (float) $payload['amount'] <= 0) {
            throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, 'Amount must be greater than zero.');
        }
        if (isset($payload['exchange_rate']) && (float) $payload['exchange_rate'] <= 0) {
            throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, 'Exchange rate must be greater than zero.');
        }
    }

    private function recordActivity(User $user, ChatSession $chat, AgentProposedAction $action, $resource): void
    {
        Activity::create([
            'actor_type' => $user->getMorphClass(),
            'actor_id' => $user->getKey(),
            'action' => $action->action_type,
            'subject_type' => $resource->getMorphClass(),
            'subject_id' => $resource->getKey(),
            'context_type' => $chat->getMorphClass(),
            'context_id' => $chat->getKey(),
            'source' => 'agent',
            'source_id' => (string) $action->id,
            'summary' => $action->summary,
            'properties' => [
                'tool_name' => $action->tool_name,
                'idempotency_key' => $action->idempotency_key,
                'proposed_action_id' => $action->id,
            ],
            'occurred_at' => now(),
        ]);
    }

    /**
     * @return array{0: ChatMessage, 1: ChatMessage}
     */
    private function dispatchTurn(ChatSession $session, User $user, array $validated, Request $request): array
    {
        $language = $request->header('Accept-Language') ?? app()->getLocale();

        $userMessage = $session->messages()->create([
            'user_id' => $user->getKey(),
            'role' => ChatMessage::ROLE_USER,
            'content' => $validated['message'],
            'format_hint' => $validated['format_hint'] ?? null,
            'language' => $language,
        ]);

        $assistantMessage = $session->messages()->create([
            'user_id' => null,
            'role' => ChatMessage::ROLE_ASSISTANT,
            'status' => ChatMessage::STATUS_PENDING,
            'language' => $language,
        ]);

        // When attachments are still uploading, hold the job: dispatching now
        // would race the file upload and the agent would never see the file.
        // uploadFiles() dispatches once the files have landed.
        if (! ($validated['defer_processing'] ?? false)) {
            ProcessChatMessageJob::dispatch($assistantMessage);
        }

        return [$userMessage, $assistantMessage];
    }

    /**
     * Reflect a confirmed/rejected action onto the stored chat message so a
     * reload renders the proposed-action card as done instead of an open form.
     */
    /**
     * Resolve the proposal's tool and recompute its summary + review fields for
     * the (possibly overridden) payload. Best-effort: returns null if the tool
     * can't be resolved, since this only refreshes display text.
     *
     * @return array{summary: string, fields: array<int, array<string, mixed>>}|null
     */
    private function describeProposal(AgentProposedAction $action, User $user): ?array
    {
        try {
            $registry = app(ToolRegistry::class);
            if (! $registry->has($action->tool_name)) {
                return null;
            }
            $tool = $registry->resolve($action->tool_name);
            if (! $tool instanceof AbstractWriteTool) {
                return null;
            }

            $context = ToolContext::forUser($user, null, ['chat_session_id' => $action->chat_session_id]);

            return $tool->describe($action->payload, $context);
        } catch (Throwable $e) {
            Log::warning('Failed to regenerate proposal description', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array{summary?: string, fields?: array}|null  $described  Regenerated
     *   summary/fields to reflect confirmed edits on the rendered block.
     */
    private function markActionBlockStatus(AgentProposedAction $action, string $status, ?array $described = null): void
    {
        $message = $action->message;

        if ($message === null) {
            return;
        }

        $result = $message->result ?? [];
        $blocks = $result['blocks'] ?? [];

        if (! is_array($blocks)) {
            return;
        }

        $changed = false;
        foreach ($blocks as &$block) {
            if (
                is_array($block)
                && ($block['type'] ?? null) === 'proposed_action'
                && (int) ($block['id'] ?? 0) === (int) $action->id
            ) {
                $block['status'] = $status;
                if ($described !== null) {
                    $block['summary'] = $described['summary'];
                    $block['fields'] = $described['fields'];
                    $block['payload'] = $action->payload;
                }
                $changed = true;
            }
        }
        unset($block);

        if ($changed) {
            $result['blocks'] = $blocks;
            $message->update(['result' => $result]);
        }
    }
}
