<?php

namespace App\Http\Controllers\API\v1;

use App\Enums\TransactionType;
use App\Http\Controllers\API\ApiController;
use App\Models\Transfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Transfers', description: 'Endpoints for managing transfers')]
class TransferController extends ApiController
{
    #[OA\Post(
        path: '/transfer',
        summary: 'Create a new transfer',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount', 'type'],
                properties: [
                    new OA\Property(property: 'amount', type: 'number', format: 'float'),
                    new OA\Property(property: 'from_wallet_id', type: 'integer', example: 1),
                    new OA\Property(property: 'to_wallet_id', type: 'integer', example: 1),
                    new OA\Property(property: 'exchange_rate', type: 'number', format: 'float', example: 9.23),
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
            'amount' => 'required|numeric|min:0.01',
            'exchange_rate' => 'sometimes|numeric|min:0.01',
            'from_wallet_id' => 'required|integer|exists:wallets,id',
            'to_wallet_id' => 'required|integer|exists:wallets,id',
        ]);

        if (! $validationResult['isValidated']) {
            return $this->failure($validationResult['message'], $validationResult['code'], $validationResult['errors']);
        }

        $data = $validationResult['data'];

        $user = $request->user();
        $fromWallet = $user->wallets()->find($data['from_wallet_id']);
        if (is_null($fromWallet)) {
            return $this->failure('Source wallet does not exist');
        }

        $toWallet = $user->wallets()->find($data['to_wallet_id']);
        if (is_null($toWallet)) {
            return $this->failure('Destination wallet does not exist');
        }

        if ($fromWallet->balance < $data['amount']) {
            return $this->failure('Source wallet has insufficient balance');
        }

        $exchangeRate = 1;
        if ($fromWallet->currency != $toWallet->currency) {
            if (! $request->has('exchange_rate')) {
                return $this->failure('Please fill in an exchange rate');
            }
            $exchangeRate = $data['exchange_rate'];

        }

        $amountToReceive = bcmul($data['amount'], $exchangeRate);

        $transfer = DB::Transaction(function () use ($data, $toWallet, $fromWallet, $amountToReceive, $user, $exchangeRate) {

            // create transfer
            $transfer = Transfer::create([
                'amount' => $data['amount'],
                'from_wallet_id' => $data['from_wallet_id'],
                'to_wallet_id' => $data['to_wallet_id'],
                'user_id' => $user->id,
                'exchange_rate' => $exchangeRate,
            ]);

            // deduct from source wallet
            $fromWallet->balance -= $data['amount'];
            $fromWallet->save();

            $user->transactions()->create([
                'amount' => $data['amount'],
                'datetime' => now(),
                'type' => TransactionType::EXPENSE->value,
                'description' => "Money transfer from $fromWallet->currency wallet to $toWallet->currency wallet",
                'wallet_id' => $fromWallet->id,
                'transfer_id' => $transfer->id,
            ]);

            // send  to destination wallet
            $toWallet->balance = bcadd($toWallet->balance, $amountToReceive, 4);
            $toWallet->save();

            $user->transactions()->create([
                'amount' => $amountToReceive,
                'datetime' => now(),
                'type' => TransactionType::INCOME->value,
                'description' => "Money transfer from $fromWallet->currency wallet to $toWallet->currency wallet",
                'wallet_id' => $toWallet->id,
                'transfer_id' => $transfer->id,
            ]);

            return $transfer;
        });

        return $this->success($transfer, statusCode: 201);
    }
}
