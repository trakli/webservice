<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

/**
 * @OA\Tag(name="Groups", description="Endpoints for managing groups")
 */
class GroupController extends ApiController
{
    #[OA\Get(
        path: '/groups',
        summary: 'List all groups',
        tags: ['Groups'],
        parameters: [
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
                description: 'Successful operation',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/Group')
                )
            ),
            new OA\Response(
                response: 500,
                description: 'Internal server error'
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
        $groups = $user->groups()->paginate($limit);

        return $this->success($groups);
    }

    #[OA\Post(
        path: '/groups',
        summary: 'Create a new group',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'client_id', type: 'string', format: 'uuid',
                        description: 'Unique identifier for your local client'),
                    new OA\Property(
                        property: 'name',
                        description: 'Name of the group',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'description',
                        description: 'Description of the group',
                        type: 'string'
                    ),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                ]
            )
        ),
        tags: ['Groups'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Group created successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/Group')
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            ),
            new OA\Response(
                response: 500,
                description: 'Internal server error'
            ),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'nullable|uuid',
            'name' => 'required|string|max:255',
            'description' => 'sometimes|string|max:255',
            'created_at' => ['nullable', 'date_format:Y-m-d H:i:s'],
        ]);

        if ($validator->fails()) {
            return $this->failure('Validation error', 400, $validator->errors()->all());
        }

        $user = $request->user();
        try {
            /** @var Group */
            $group = $user->groups()->create($validator->validated());

            if (isset($request['client_id'])) {
                $group->setClientGeneratedId($request['client_id']);
            }
            $group->markAsSynced();
        } catch (\Exception $e) {
            return $this->failure('Failed to create group', 500, [$e->getMessage()]);
        }

        return $this->success($group, 'Group created successfully', 201);
    }

    #[OA\Get(
        path: '/groups/{id}',
        summary: 'Get a specific group',
        tags: ['Groups'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'ID of the group',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(ref: '#/components/schemas/Group')
            ),
            new OA\Response(
                response: 404,
                description: 'Group not found'
            ),
            new OA\Response(
                response: 500,
                description: 'Internal server error'
            ),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        $user = request()->user();
        $group = $user->groups()->find($id);

        if (! $group) {
            return $this->failure('Group not found', 404);
        }

        return $this->success($group);
    }

    #[OA\Put(
        path: '/groups/{id}',
        summary: 'Update a specific group',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'name',
                        description: 'Name of the group',
                        type: 'string'
                    ),
                ]
            )
        ),
        tags: ['Groups'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'ID of the group',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Group updated successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/Group')
            ),
            new OA\Response(
                response: 404,
                description: 'Group not found'
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            ),
            new OA\Response(
                response: 500,
                description: 'Internal server error'
            ),
        ]
    )]
    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->failure('Validation error', 400, $validator->errors()->all());
        }

        $user = $request->user();
        $group = $user->groups()->find($id);

        if (! $group) {
            return $this->failure('Group not found', 404);
        }

        $group->update($validator->validated());

        return $this->success($group, 'Group updated successfully');
    }

    #[OA\Delete(
        path: '/groups/{id}',
        summary: 'Delete a specific group',
        tags: ['Groups'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'ID of the group',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 204,
                description: 'Group deleted successfully'
            ),
            new OA\Response(
                response: 404,
                description: 'Group not found'
            ),
            new OA\Response(
                response: 500,
                description: 'Internal server error'
            ),
        ]
    )]
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $group = $user->groups()->find($id);

        if (! $group) {
            return $this->failure('Group not found', 404);
        }

        $group->delete();

        return $this->success(null, 'Group deleted successfully', 204);
    }
}
