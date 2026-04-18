<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Jobs\ProcessChatMessageJob;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\AiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

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

    private function authorizeOwnership(User $user, ChatSession $session): void
    {
        if ($session->owner_type !== $user->getMorphClass() || $session->owner_id !== $user->getKey()) {
            abort(Response::HTTP_NOT_FOUND, 'Chat session not found.');
        }
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

        ProcessChatMessageJob::dispatch($assistantMessage);

        return [$userMessage, $assistantMessage];
    }
}
