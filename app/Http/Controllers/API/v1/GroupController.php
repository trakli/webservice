<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Models\Group;
use App\Rules\Iso8601DateTime;
use App\Services\FileService;
use Carbon\Carbon;
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
            new OA\Parameter(
                name: 'sync_from',
                description: 'Get recent changes after this date',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string')
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
        $last_synced = now();
        $user = request()->user();
        $limit = 20;
        if ($request->has('limit')) {
            $limit = $request->limit;
        }
        $groups = $user->groups();
        if ($request->has('sync_from')) {
            try {
                $date = Carbon::parse($request->sync_from);
                $groups = $groups->where('updated_at', '>=', $date);
            } catch (\Exception $exception) {
                return $this->failure('Invalid date', 422);
            }
        }

        $groups = $groups->paginate($limit);

        return $this->success($groups, last_synced: $last_synced);
    }

    #[OA\Post(
        path: '/groups',
        summary: 'Create a new group',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'client_id', description: 'Unique identifier for your local client', type: 'string',
                        format: 'uuid'),
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
                    new OA\Property(property: 'icon', description: 'The icon of the group (file or icon string)', type: 'string'),
                    new OA\Property(property: 'icon_type', description: 'The type of the icon (icon or emoji or  image)', type: 'string'),
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
            'icon' => 'nullable',
            'icon_type' => 'required_with:icon|string|in:icon,image,emoji',
            'created_at' => ['nullable', new Iso8601DateTime],
        ]);

        if ($validator->fails()) {
            return $this->failure('Validation error', 422, $validator->errors()->all());
        }
        $data = $validator->validated();
        $user = $request->user();
        try {
            $group = DB::transaction(function () use ($data, $request, $user) {

                /** @var Group $group */
                $group = $user->groups()->create($data);

                if (isset($request['client_id'])) {
                    $group->setClientGeneratedId($request['client_id']);
                }
                $group->markAsSynced();

                FileService::updateIcon($group, $data, $request);

                return $group;

            });
            $group->refresh();

            return $this->success($group, 'Group created successfully', 201);

        } catch (ValidationException $e) {
            return $this->failure('Validation error', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->failure('Failed to create group', 500, [$e->getMessage()]);
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
                    new OA\Property(property: 'icon', description: 'The icon of the group (file or icon string)', type: 'string'),
                    new OA\Property(property: 'icon_type', description: 'The type of the icon (icon or emoji or  image)', type: 'string'),
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
            'icon' => 'nullable',
            'icon_type' => 'required_with:icon|string|in:icon,image,emoji',
        ]);

        if ($validator->fails()) {
            return $this->failure('Validation error', 422, $validator->errors()->all());
        }

        $user = $request->user();
        $group = $user->groups()->find($id);
        $data = $validator->validated();

        if (! $group) {
            return $this->failure('Group not found', 404);
        }
        try {
            DB::transaction(function () use ($data, $request, &$group) {
                $group->update($data);
                FileService::updateIcon($group, $data, $request);
            });

            $group->refresh();

            return $this->success($group, 'Group updated successfully');
        } catch (ValidationException $e) {
            return $this->failure('Validation error', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->failure('Failed to update group', 500, [$e->getMessage()]);
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
