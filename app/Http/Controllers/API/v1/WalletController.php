<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * @OA\Tag(name="Wallet", description="Operations related to wallets")
 */
class WalletController extends ApiController
{
    #[OA\Get(
        path: '/wallets',
        summary: 'List all wallets',
        tags: ['Wallet'],
        parameters: [
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/Wallet')
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized'
            ),
            new OA\Response(
                response: 500,
                description: 'Server error'
            ),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = 20;
        if ($request->has('limit')) {
            $limit = $request->limit;
        }
        $wallets = $user->wallets()->paginate($limit);

        return $this->success($wallets);
    }

    #[OA\Post(
        path: '/wallets',
        summary: 'Create a new wallet',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'type'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Personal Cash'),
                    new OA\Property(property: 'type', type: 'string', example: 'cash'),
                    new OA\Property(property: 'description', type: 'string', example: 'Personal cash wallet'),
                ]
            )
        ),
        tags: ['Wallet'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Wallet created successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/Wallet')
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized'
            ),
            new OA\Response(
                response: 500,
                description: 'Server error'
            ),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:bank,cash,credit_card,mobile',
            'description' => 'sometimes|string',
        ]);

        $user = $request->user();
        $validatedData['user_id'] = $user->id;
        $wallet = $user->wallets()->firstOrCreate($validatedData);

        return $this->success($wallet, 'Wallet created successfully', 201);
    }

    #[OA\Get(
        path: '/wallets/{id}',
        summary: 'Get a specific wallet',
        tags: ['Wallet'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(ref: '#/components/schemas/Wallet')
            ),
            new OA\Response(
                response: 404,
                description: 'Wallet not found'
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized'
            ),
            new OA\Response(
                response: 500,
                description: 'Server error'
            ),
        ]
    )]
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $wallet = $user->wallets()->find($id);

        if (! $wallet) {
            return $this->failure('Wallet not found', 404);
        }

        return $this->success($wallet);
    }

    #[OA\Put(
        path: '/wallets/{id}',
        summary: 'Update a specific wallet',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'type'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Updated Wallet'),
                    new OA\Property(property: 'type', type: 'string', example: 'bank'),
                    new OA\Property(property: 'description', type: 'string', example: 'Updated wallet description'),
                ]
            )
        ),
        tags: ['Wallet'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Wallet updated successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/Wallet')
            ),
            new OA\Response(
                response: 404,
                description: 'Wallet not found'
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized'
            ),
            new OA\Response(
                response: 500,
                description: 'Server error'
            ),
        ]
    )]
    public function update(Request $request, int $id): JsonResponse
    {
        $validatedData = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|string',
            'description' => 'sometimes|string',
        ]);
        $user = $request->user();

        $wallet = $user->wallets()->find($id);

        if (! $wallet) {
            return $this->failure('Wallet not found', 404);
        }

        $wallet->update($validatedData);

        return $this->success($wallet, 'Wallet updated successfully');
    }

    #[OA\Delete(
        path: '/wallets/{id}',
        summary: 'Delete a specific wallet',
        tags: ['Wallet'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Wallet deleted successfully'
            ),
            new OA\Response(
                response: 404,
                description: 'Wallet not found'
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized'
            ),
            new OA\Response(
                response: 500,
                description: 'Server error'
            ),
        ]
    )]
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $wallet = $user->wallets()->find($id);

        if (! $wallet) {
            return $this->failure('Wallet not found', 404);
        }

        $wallet->delete();

        return $this->success(null, 'Wallet deleted successfully');
    }
}
