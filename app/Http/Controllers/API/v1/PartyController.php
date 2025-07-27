<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Http\Traits\ApiQueryable;
use App\Models\Party;
use App\Rules\Iso8601DateTime;
use App\Rules\ValidateClientId;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * @OA\Tag(name="Party", description="Operations related to parties")
 */
class PartyController extends ApiController
{
    use ApiQueryable;

    #[OA\Get(
        path: '/parties',
        summary: 'List all parties',
        tags: ['Party'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/limitParam'),
            new OA\Parameter(ref: '#/components/parameters/syncedSinceParam'),
            new OA\Parameter(ref: '#/components/parameters/noClientIdParam'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'last_sync', type: 'string', format: 'date-time'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Party')
                        ),
                    ],
                    type: 'object'
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
        $partiesQuery = $user->parties();

        try {
            $data = $this->applyApiQuery($request, $partiesQuery);

            return $this->success($data);
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/parties',
        summary: 'Create a new party',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'client_id', description: 'Unique identifier for your local client', type: 'string',
                        format: 'string', example: '245cb3df-df3a-428b-a908-e5f74b8d58a3:245cb3df-df3a-428b-a908-e5f74b8d58a4'),
                    new OA\Property(property: 'name', type: 'string', example: 'John Doe'),
                    new OA\Property(property: 'type', type: 'string', example: 'individual,organization,business,partnership,non_profit,government_agency,educational_institution,healthcare_provider'),
                    new OA\Property(property: 'description', type: 'string', example: 'Incomes from John Doe'),
                    new OA\Property(property: 'icon', description: 'The icon of the party (file or icon string)', type: 'string'),
                    new OA\Property(property: 'icon_type', description: 'The type of the icon (icon or emoji or  image)', type: 'string'),
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
            'client_id' => ['nullable', 'string', new ValidateClientId],
            'name' => 'required|string|max:255',
            'description' => 'sometimes|string',
            'icon' => 'nullable',
            'icon_type' => 'required_with:icon|string|in:icon,image,emoji',
            'type' => 'sometimes|string|in:individual,organization,business,partnership,non_profit,government_agency,educational_institution,healthcare_provider',
            'created_at' => ['nullable', new Iso8601DateTime],
        ]);

        $user = $request->user();
        $validatedData['user_id'] = $user->id;
        $existing_party = $user->parties()->where('name', $validatedData['name'])->first();
        if ($existing_party) {
            return $this->failure('Party already exists', 400);
        }

        try {
            $party = DB::transaction(function () use ($validatedData, $request, $user) {
                /** @var Party $party */
                $party = $user->parties()->create($validatedData);
                if (! empty($validatedData['client_id'])) {
                    $party->setClientGeneratedId($validatedData['client_id'], $user);
                }
                $party->markAsSynced();
                FileService::updateIcon($party, $validatedData, $request);

                return $party;
            });

            $party->refresh();

            return $this->success($party, 'Party created successfully', 201);

        } catch (ValidationException $e) {
            return $this->failure('Validation error', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->failure('Failed to create party', 500, [$e->getMessage()]);
        }

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
                    new OA\Property(property: 'client_id', description: 'Unique identifier for your local client', type: 'string'),
                    new OA\Property(property: 'name', type: 'string', example: 'Jane Doe'),
                    new OA\Property(property: 'type', type: 'string', example: 'individual,organization,business,partnership,non_profit,government_agency,educational_institution,healthcare_provider'),
                    new OA\Property(property: 'description', type: 'string', example: 'income from John Doe'),
                    new OA\Property(property: 'icon', description: 'The icon of the party (file or icon string)', type: 'string'),
                    new OA\Property(property: 'icon_type', description: 'The type of the icon (icon or emoji or  image)', type: 'string'),
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
            'client_id' => ['nullable', 'string', new ValidateClientId],
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|string',
            'icon' => 'nullable',
            'icon_type' => 'required_with:icon|string|in:icon,image,emoji',
            'type' => 'sometimes|string|in:individual,organization,business,partnership,non_profit,government_agency,educational_institution,healthcare_provider',
        ]);

        $user = $request->user();
        $party = $user->parties()->find($id);

        if (! $party) {
            return $this->failure('Party not found', 404);
        }
        try {
            DB::transaction(function () use ($validatedData, $request, &$party) {
                $this->updateModel($party, $validatedData, $request);
            });

            $party->refresh();

            return $this->success($party, 'Party updated successfully');
        } catch (ValidationException $e) {
            return $this->failure('Validation error', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->failure('Failed to update party', 500, [$e->getMessage()]);
        }
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
