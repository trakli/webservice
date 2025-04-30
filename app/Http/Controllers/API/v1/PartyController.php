<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Models\Party;
use App\Rules\Iso8601Date;
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
        parameters: [
            new OA\Property(property: 'client_id', type: 'string', format: 'uuid',
                description: 'Unique identifier for your local client'),
            new OA\Parameter(
                name: 'limit',
                description: 'Number of items per page',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 20)
            ),

        ],
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
    public function index(Request $request): JsonResponse
    {
        $user = request()->user();
        $limit = 20;
        if ($request->has('limit')) {
            $limit = $request->limit;
        }
        $parties = $user->parties()->paginate($limit);

        return $this->success($parties);
    }

    #[OA\Post(
        path: '/parties',
        summary: 'Create a new party',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'client_id', type: 'string', format: 'uuid',
                        description: 'Unique identifier for your local client'),
                    new OA\Property(property: 'name', type: 'string', example: 'John Doe'),
                    new OA\Property(property: 'description', type: 'string', example: 'Incomes from John Doe'),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),

                ]
            )
        ),
        tags: ['Party'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Party created successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/Party')
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input|Party already exists'
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
            'client_id' => 'nullable|uuid',
            'name' => 'required|string|max:255',
            'description' => 'sometimes|string',
            'created_at' => ['nullable', new Iso8601Date],
        ]);

        $user = $request->user();
        $validatedData['user_id'] = $user->id;
        $party = $user->parties()->where('name', $validatedData['name'])->first();
        if ($party) {
            return $this->failure('Party already exists', 400);
        }

        try {
            /** @var Party */
            $party = $user->parties()->create($validatedData);
            if (! empty($validatedData['client_id'])) {
                $party->setClientGeneratedId($validatedData['client_id']);
            }
            $party->markAsSynced();
        } catch (\Exception $e) {
            return $this->failure('Failed to create party', 500, [$e->getMessage()]);
        }

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
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Jane Doe'),
                    new OA\Property(property: 'description', type: 'string', example: 'income from John Doe'),
                ]
            )
        ),
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
        $validatedData = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
        ]);

        $user = $request->user();
        $party = $user->parties()->find($id);

        if (! $party) {
            return $this->failure('Party not found', 404);
        }

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
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = request()->user();
        $party = $user->parties()->find($id);

        if (! $party) {
            return $this->failure('Party not found', 404);
        }

        $party->delete();

        return $this->success(null, 'Party deleted successfully');
    }
}
