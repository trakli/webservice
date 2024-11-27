<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * @OA\Tag(name="Party", description="Operations related to parties")
 */
class PartyController extends ApiController
{
    #[OA\Get(
        path: '/parties',
        summary: 'List all parties',
        tags: ['Party'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/Party')
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
    public function index(): JsonResponse
    {
        $parties = Party::paginate(20);

        return $this->success($parties);
    }

    #[OA\Post(
        path: '/parties',
        summary: 'Create a new party',
        tags: ['Party'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'John Doe'),
                    new OA\Property(property: 'user_id', type: 'integer', example: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Party created successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/Party')
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
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $party = Party::create($validatedData);

        return $this->success($party, 'Party created successfully', 201);
    }

    #[OA\Get(
        path: '/parties/{id}',
        summary: 'Get a specific party',
        tags: ['Party'],
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
                content: new OA\JsonContent(ref: '#/components/schemas/Party')
            ),
            new OA\Response(
                response: 404,
                description: 'Party not found'
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
    public function show(int $id): JsonResponse
    {
        $party = Party::find($id);

        if (! $party) {
            return $this->failure('Party not found', 404);
        }

        return $this->success($party);
    }

    #[OA\Put(
        path: '/parties/{id}',
        summary: 'Update a specific party',
        tags: ['Party'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Jane Doe'),
                    new OA\Property(property: 'user_id', type: 'integer', example: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Party updated successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/Party')
            ),
            new OA\Response(
                response: 404,
                description: 'Party not found'
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
        $party = Party::find($id);

        if (! $party) {
            return $this->failure('Party not found', 404);
        }

        $validatedData = $request->validate([
            'name' => 'sometimes|string|max:255',
            'user_id' => 'sometimes|integer|exists:users,id',
        ]);

        $party->update($validatedData);

        return $this->success($party, 'Party updated successfully');
    }

    #[OA\Delete(
        path: '/parties/{id}',
        summary: 'Delete a specific party',
        tags: ['Party'],
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
                description: 'Party deleted successfully'
            ),
            new OA\Response(
                response: 404,
                description: 'Party not found'
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
    public function destroy(int $id): JsonResponse
    {
        $party = Party::find($id);

        if (! $party) {
            return $this->failure('Party not found', 404);
        }

        $party->delete();

        return $this->success(null, 'Party deleted successfully');
    }
}
