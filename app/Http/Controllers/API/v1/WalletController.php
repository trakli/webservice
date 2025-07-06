<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Models\Wallet;
use App\Rules\Iso8601DateTime;
use App\Services\FileService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
        $last_synced = now();
        $user = $request->user();
        $limit = 20;
        if ($request->has('limit')) {
            $limit = $request->limit;
        }
        $wallets = $user->wallets();

        if ($request->has('sync_from')) {
            try {
                $date = Carbon::parse($request->sync_from);
                $wallets = $wallets->where('updated_at', '>=', $date);
            } catch (\Exception $exception) {
                return $this->failure('Invalid date', 422);
            }
        }
        $wallets = $wallets->paginate($limit);

        return $this->success($wallets, last_synced: $last_synced);
    }

    #[OA\Post(
        path: '/wallets',
        summary: 'Create a new wallet',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'type', 'currency'],
                properties: [
                    new OA\Property(property: 'client_id', description: 'Unique identifier for your local client', type: 'string',
                        format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string', example: 'Personal Cash'),
                    new OA\Property(property: 'type', type: 'string', example: 'cash'),
                    new OA\Property(property: 'description', type: 'string', example: 'Personal cash wallet'),
                    new OA\Property(property: 'currency', type: 'string', pattern: '^[A-Z]{3}$', example: 'XAF'),
                    new OA\Property(property: 'balance', type: 'number', format: 'float', example: 12.00),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'icon', description: 'The icon of the wallet (file or icon string)', type: 'string'),
                    new OA\Property(property: 'icon_type', description: 'The type of the icon (icon or emoji or  image)', type: 'string'),

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
            'client_id' => 'nullable|uuid',
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:bank,cash,credit_card,mobile',
            'description' => 'sometimes|string',
            'currency' => 'required|string|size:3',
            'balance' => 'sometimes|numeric|decimal:0,4',
            'icon' => 'nullable',
            'icon_type' => 'required_with:icon|string|in:icon,image,emoji',
            'created_at' => ['nullable', new Iso8601DateTime],
        ]);

        $user = $request->user();
        $validatedData['user_id'] = $user->id;
        try {
            $wallet = DB::transaction(function () use ($validatedData, $request, $user) {

                /** @var Wallet $wallet */
                $wallet = $user->wallets()->create($validatedData);
                if (isset($request['client_id'])) {
                    $wallet->setClientGeneratedId($request['client_id']);
                }
                $wallet->markAsSynced();

                FileService::updateIcon($wallet, $validatedData, $request);

                return $wallet;
            });
            $wallet->refresh();

            return $this->success($wallet, 'Wallet created successfully', 201);

        } catch (ValidationException $e) {
            return $this->failure('Validation error', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->failure('Failed to create wallet', 500, [$e->getMessage()]);
        }
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
                    new OA\Property(property: 'icon', description: 'The icon of the wallet (file or icon string)', type: 'string'),
                    new OA\Property(property: 'icon_type', description: 'The type of the icon (icon or emoji or  image)', type: 'string'),
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
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|string',
            'description' => 'sometimes|string',
            'currency' => 'sometimes|required|string|size:3',
            'balance' => 'sometimes|numeric|decimal:0,4',
            'icon' => 'nullable',
            'icon_type' => 'required_with:icon|string|in:icon,image,emoji',
        ]);
        $user = $request->user();

        $wallet = $user->wallets()->find($id);

        if (! $wallet) {
            return $this->failure('Wallet not found', 404);
        }
        try {
            DB::transaction(function () use ($validatedData, $request, &$wallet) {
                $wallet->update($validatedData);
                FileService::updateIcon($wallet, $validatedData, $request);
            });

            $wallet->refresh();

            return $this->success($wallet, 'Wallet updated successfully');
        } catch (ValidationException $e) {
            return $this->failure('Validation error', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->failure('Failed to update wallet', 500, [$e->getMessage()]);
        }
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
