<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Http\Traits\ApiQueryable;
use App\Models\Group;
use App\Rules\Iso8601DateTime;
use App\Rules\ValidateClientId;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * @OA\Tag(name="Groups", description="Endpoints for managing groups")
 */
class GroupController extends ApiController
{
    use ApiQueryable;

    #[OA\Get(
        path: '/groups',
        summary: 'List all groups',
        tags: ['Groups'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/limitParam'),
            new OA\Parameter(ref: '#/components/parameters/syncedSinceParam'),
            new OA\Parameter(ref: '#/components/parameters/noClientIdParam'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'last_sync', type: 'string', format: 'date-time'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Group')
                        ),
                    ],
                    type: 'object'
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
        $user = $request->user();
        $groupsQuery = $user->groups();

        try {
            $data = $this->applyApiQuery($request, $groupsQuery);

            return $this->success($data);
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/groups',
        summary: 'Create a new group',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(
                        property: 'client_id',
                        description: 'Unique identifier for your local client',
                        type: 'string',
                        format: 'string',
                        example: '245cb3df-df3a-428b-a908-e5f74b8d58a3:245cb3df-df3a-428b-a908-e5f74b8d58a4'
                    ),
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
                    new OA\Property(
                        property: 'icon',
                        description: 'The icon of the group (file or icon string)',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'icon_type',
                        description: 'The type of the icon (icon or emoji or  image)',
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
            'client_id' => ['nullable', 'string', new ValidateClientId()],
            'name' => 'required|string|max:255',
            'description' => 'sometimes|string|max:255',
            'icon' => 'nullable',
            'icon_type' => 'required_with:icon|string|in:icon,image,emoji',
            'created_at' => ['nullable', new Iso8601DateTime()],
        ]);

        if ($validator->fails()) {
            return $this->failure(__('Validation error'), 422, $validator->errors()->all());
        }
        $data = $validator->validated();
        $user = $request->user();

        $existingGroup = Group::where('user_id', $user->id)
            ->where('name', $data['name'])
            ->first();

        if ($existingGroup) {
            if (isset($request['client_id']) && $existingGroup->client_generated_id !== $request['client_id']) {
                $existingGroup->setClientGeneratedId($request['client_id'], $user);
                $existingGroup->markAsSynced();
            }
            $existingGroup->refresh();

            return $this->success($existingGroup, __('Group already exists'), 200);
        }

        try {
            $group = DB::transaction(function () use ($data, $request, $user) {

                /** @var Group $group */
                $group = $user->groups()->create($data);

                if (isset($request['client_id'])) {
                    $group->setClientGeneratedId($request['client_id'], $user);
                }
                $group->markAsSynced();

                FileService::updateIcon($group, $data, $request);

                return $group;
            });
            $group->refresh();

            return $this->success($group, __('Group created successfully'), 201);
        } catch (ValidationException $e) {
            return $this->failure(__('Validation error'), 422, $e->errors());
        } catch (\Exception $e) {
            return $this->failure(__('Failed to create group'), 500, [$e->getMessage()]);
        }
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
    public function show(int $groupId): JsonResponse
    {
        $user = request()->user();
        $group = $user->groups()->find($groupId);

        if (! $group) {
            return $this->failure(__('Group not found'), 404);
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
                        property: 'client_id',
                        description: 'Unique identifier for your local client',
                        type: 'string',
                        format: 'string',
                        example: '245cb3df-df3a-428b-a908-e5f74b8d58a3:245cb3df-df3a-428b-a908-e5f74b8d58a4'
                    ),
                    new OA\Property(
                        property: 'name',
                        description: 'Name of the group',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'icon',
                        description: 'The icon of the group (file or icon string)',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'icon_type',
                        description: 'The type of the icon (icon or emoji or  image)',
                        type: 'string'
                    ),
                    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
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
    public function update(Request $request, int $groupId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => ['nullable', 'string', new ValidateClientId()],
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|string|max:255',
            'icon' => 'nullable',
            'icon_type' => 'required_with:icon|string|in:icon,image,emoji',
            'updated_at' => ['nullable', new Iso8601DateTime()],
        ]);

        if ($validator->fails()) {
            return $this->failure(__('Validation error'), 422, $validator->errors()->all());
        }

        $user = $request->user();
        $group = $user->groups()->find($groupId);
        $data = $validator->validated();

        if (isset($data['updated_at'])) {
            $data['updated_at'] = format_iso8601_to_sql($data['updated_at']);
        }

        if (! $group) {
            return $this->failure(__('Group not found'), 404);
        }
        try {
            $this->checkUpdatedAt($group, $data);

            DB::transaction(function () use ($data, $request, &$group) {
                $this->updateModel($group, $data, $request);
            });

            $group->refresh();

            return $this->success($group, __('Group updated successfully'));
        } catch (ValidationException $e) {
            return $this->failure(__('Validation error'), 422, $e->errors());
        } catch (\Exception $e) {
            return $this->failure(__('Failed to update group'), 500, [$e->getMessage()]);
        }
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
    public function destroy(Request $request, int $groupId): JsonResponse
    {
        $user = $request->user();
        $group = $user->groups()->find($groupId);

        if (! $group) {
            return $this->failure(__('Group not found'), 404);
        }

        $group->delete();

        return $this->success(null, __('Group deleted successfully'), 204);
    }
}
