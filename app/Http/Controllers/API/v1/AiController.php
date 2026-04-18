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
use Illuminate\Support\Str;
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

        $session = ChatSession::create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'title' => $validated['title'] ?? Str::limit($validated['message'], 60),
        ]);

        $this->dispatchTurn($session, $user, $validated, $request);

        return $this->success(
            $session->fresh()->load('messages'),
            __('Chat session created.'),
            Response::HTTP_ACCEPTED
        );
    }

    #[OA\Get(
        path: '/ai/chats/{id}',
        summary: 'Get a chat session with all its messages',
        tags: ['AI'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Chat session with messages'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Request $request, int $sessionId): JsonResponse
    {
        $session = $this->findOwnedSession($request, $sessionId);

        return $this->success($session->load('messages'));
    }

    #[OA\Post(
        path: '/ai/chats/{id}/messages',
        summary: 'Add a follow-up message to an existing chat session',
        tags: ['AI'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
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
    public function storeMessage(Request $request, int $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
            'format_hint' => 'nullable|string|in:scalar,pair,record,list,pair_list,table,raw',
        ]);

        $session = $this->findOwnedSession($request, $sessionId);
        $user = $request->user();

        [$userMessage, $assistantMessage] = $this->dispatchTurn($session, $user, $validated, $request);
        $session->touch();

        return $this->success(
            ['user' => $userMessage, 'assistant' => $assistantMessage],
            __('Message queued.'),
            Response::HTTP_ACCEPTED
        );
    }

    #[OA\Delete(
        path: '/ai/chats/{id}',
        summary: 'Delete a chat session',
        tags: ['AI'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Request $request, int $sessionId): JsonResponse
    {
        $session = $this->findOwnedSession($request, $sessionId);
        $session->delete();

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

    private function findOwnedSession(Request $request, int $sessionId): ChatSession
    {
        $session = ChatSession::query()
            ->ownedBy($request->user())
            ->find($sessionId);

        if ($session === null) {
            abort(Response::HTTP_NOT_FOUND, 'Chat session not found.');
        }

        return $session;
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
