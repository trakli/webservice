<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Models\Transfer;
use App\Models\User;
use App\Rules\Iso8601DateTime;
use App\Rules\ValidateClientId;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Whilesmart\UserDevices\Models\Device;

#[OA\Tag(name: 'Transfers', description: 'Endpoints for managing transfers')]
class TransferController extends ApiController
{
    private TransferService $transferService;

    public function __construct(TransferService $transferService)
    {
        $this->transferService = $transferService;
    }

    #[OA\Post(
        path: '/transfer',
        summary: 'Create a new transfer',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount', 'type'],
                properties: [
                    new OA\Property(property: 'client_id', description: 'Unique identifier for your local client', type: 'string',
                        format: 'string', example: '245cb3df-df3a-428b-a908-e5f74b8d58a3:245cb3df-df3a-428b-a908-e5f74b8d58a4'),
                    new OA\Property(property: 'amount', type: 'number', format: 'float'),
                    new OA\Property(property: 'from_wallet_id', type: 'integer', example: 1),
                    new OA\Property(property: 'to_wallet_id', type: 'integer', example: 1),
                    new OA\Property(property: 'exchange_rate', type: 'number', format: 'float', example: 9.23),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),

                ]
            )
        ),
        tags: ['Transfers'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Transfer created successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/Transfer')
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            ),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validationResult = $this->validateRequest($request, [
            'client_id' => ['nullable', 'string', new ValidateClientId],
            'amount' => 'required|numeric|min:0.01',
            'exchange_rate' => 'sometimes|numeric|min:0.01',
            'from_wallet_id' => 'required|integer|exists:wallets,id',
            'to_wallet_id' => 'required|integer|exists:wallets,id',
            'created_at' => ['nullable', new Iso8601DateTime],
        ]);

        if (! $validationResult['isValidated']) {
            return $this->failure($validationResult['message'], $validationResult['code'], $validationResult['errors']);
        }

        $data = $validationResult['data'];
        $user = $request->user();

        if (! empty($data['client_id'])) {
            $existingTransfer = $this->findTransferByClientId($data['client_id'], $user);
            if ($existingTransfer) {
                return $this->success($existingTransfer, statusCode: 200);
            }
        }
        $fromWallet = $user->wallets()->find($data['from_wallet_id']);
        if (is_null($fromWallet)) {
            return $this->failure(__('Source wallet does not exist'));
        }

        $toWallet = $user->wallets()->find($data['to_wallet_id']);
        if (is_null($toWallet)) {
            return $this->failure(__('Destination wallet does not exist'));
        }

        if ($fromWallet->balance < $data['amount']) {
            return $this->failure(__('Source wallet has insufficient balance'));
        }

        $exchangeRate = 1;
        if ($fromWallet->currency != $toWallet->currency) {
            if (! $request->has('exchange_rate')) {
                return $this->failure(__('Please fill in an exchange rate'));
            }
            $exchangeRate = $data['exchange_rate'];

        }

        $amountToReceive = bcmul($data['amount'], $exchangeRate);

        $fullClientId = $data['client_id'] ?? null;
        $deviceToken = null;
        $randomId = null;
        if ($fullClientId && count($parts = explode(':', $fullClientId)) === 2) {
            $deviceToken = $parts[0];
            $randomId = $parts[1];
        }

        $transfer = DB::Transaction(function () use ($data, $toWallet, $fromWallet, $amountToReceive, $user, $exchangeRate, $deviceToken, $randomId) {
            $transfer = $this->transferService->transfer(
                amountToSend: $data['amount'],
                fromWallet: $fromWallet,
                amountToReceive: $amountToReceive,
                toWallet: $toWallet,
                user: $user,
                exchangeRate: $exchangeRate,
                deviceToken: $deviceToken
            );

            if ($randomId && $deviceToken) {
                $transfer->setClientGeneratedId($randomId, $user, $deviceToken);
            }
            $transfer->markAsSynced();

            return $transfer;
        });

        return $this->success($transfer, statusCode: 201);
    }

    private function findTransferByClientId(string $clientId, User $user): ?Transfer
    {
        $parts = explode(':', $clientId);
        if (count($parts) !== 2) {
            return null;
        }

        $deviceToken = $parts[0];
        $randomId = $parts[1];

        $device = Device::where('token', $deviceToken)->first();
        if (! $device) {
            return null;
        }

        return Transfer::query()
            ->where('user_id', $user->id)
            ->join('model_sync_states', 'transfers.id', '=', 'model_sync_states.syncable_id')
            ->where('model_sync_states.syncable_type', Transfer::class)
            ->where('model_sync_states.client_generated_id', $randomId)
            ->where('model_sync_states.device_id', $device->id)
            ->select('transfers.*')
            ->first();
    }
}
