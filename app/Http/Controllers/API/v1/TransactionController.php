<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Models\Transaction;
use App\Rules\Iso8601DateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\HttpException;

#[OA\Tag(name: 'Transactions', description: 'Endpoints for managing transactions')]
class TransactionController extends ApiController
{
    #[OA\Get(
        path: '/transactions',
        summary: 'List all transactions',
        tags: ['Transactions'],
        parameters: [
            new OA\Parameter(
                name: 'type',
                description: 'Type of transaction (income/expense)',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', enum: ['income', 'expense'])
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Number of transactions to fetch',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'integer', default: 20)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Transaction'))
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid transaction type'
            ),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type');
        $limit = $request->query('limit', 20);

        if (! empty($type) && ! in_array($type, ['income', 'expense'])) {
            return $this->failure('Invalid transaction type', 400);
        }

        $query = auth()->user()->transactions();
        if (! empty($type)) {
            $query->where('type', $type);
        }
        $transactions = $query->paginate($limit);

        return $this->success($transactions);
    }

    #[OA\Post(
        path: '/transactions',
        summary: 'Create a new transaction',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount', 'type'],
                properties: [
                    new OA\Property(property: 'client_id', type: 'string', format: 'uuid',
                        description: 'Unique identifier for your local client'),
                    new OA\Property(property: 'amount', type: 'number', format: 'float'),
                    new OA\Property(property: 'type', type: 'string', enum: ['income', 'expense']),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'datetime', type: 'string', format: 'datetime'),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'party_id', type: 'integer'),
                    new OA\Property(property: 'wallet_id', type: 'integer'),
                    new OA\Property(property: 'group_id', type: 'integer'),
                    new OA\Property(property: 'categories', type: 'array', items: new OA\Items(type: 'integer', description: 'Category ID array')),
                ]
            )
        ),
        tags: ['Transactions'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Transaction created successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/Transaction')
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
            'client_id' => 'nullable|uuid',
            'amount' => 'required|numeric|min:0.01',
            'type' => 'required|string|in:income,expense',
            'description' => 'nullable|string',
            'datetime' => ['nullable', new Iso8601DateTime],
            'created_at' => ['nullable', new Iso8601DateTime],
            'group_id' => 'nullable|integer|exists:groups,id',
            'party_id' => 'nullable|integer|exists:parties,id',
            'wallet_id' => 'nullable|integer|exists:wallets,id',
            'categories' => 'nullable|array',
            'categories.*' => 'integer|exists:categories,id',
        ]);

        if (! $validationResult['isValidated']) {
            return $this->failure($validationResult['message'], $validationResult['code'], $validationResult['errors']);
        }

        $request = $validationResult['data'];

        if (isset($request['datetime'])) {
            $request['datetime'] = format_iso8601_to_sql($request['datetime']);
        }

        if (isset($request['created_at'])) {
            $request['created_at'] = format_iso8601_to_sql($request['created_at']);
        }

        $categories = [];
        if (isset($request['categories'])) {
            $categories = $request['categories'];
            unset($request['categories']);
        }

        try {
            /** @var Transaction */
            $transaction = auth()->user()->transactions()->create($request);

            if (isset($request['client_id'])) {
                $transaction->setClientGeneratedId($request['client_id']);
            }

            $transaction->markAsSynced();

            if (! empty($categories)) {
                $transaction->categories()->sync($categories);
            }
        } catch (\Throwable $e) {
            logger()->error('Transaction creation error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return $this->failure('Failed to create transaction', 500, [$e->getMessage()]);
        }

        return $this->success($transaction, statusCode: 201);
    }

    #[OA\Get(
        path: '/transactions/{id}',
        summary: 'Get a specific transaction',
        tags: ['Transactions'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                description: 'ID of the transaction',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'type',
                in: 'query',
                description: 'Type of transaction (income/expense)',
                required: true,
                schema: new OA\Schema(type: 'string', enum: ['income', 'expense'])
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(ref: '#/components/schemas/Transaction')
            ),
            new OA\Response(
                response: 404,
                description: 'Transaction not found'
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid transaction type'
            ),
        ]
    )]
    public function show($id, Request $request): JsonResponse
    {
        $type = $request->query('type');

        if (! empty($type) && ! in_array($type, ['income', 'expense'])) {
            return $this->failure('Invalid transaction type', 400);
        }

        $query = Transaction::query();
        if (! empty($type)) {
            $query->where('type', $type);
        }

        $transaction = $query->find($id);

        if (! $transaction) {
            return $this->failure('Transaction not found', 404);
        }

        try {
            $this->userCanAccessResource($transaction);
        } catch (HttpException $e) {
            return $this->failure($e->getMessage(), $e->getStatusCode());
        }

        return $this->success($transaction);
    }

    #[OA\Put(
        path: '/transactions/{id}',
        summary: 'Update a specific transaction',
        tags: ['Transactions'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                description: 'ID of the transaction',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'type',
                in: 'query',
                description: 'Type of transaction (income/expense)',
                required: true,
                schema: new OA\Schema(type: 'string', enum: ['income', 'expense'])
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount', 'updated_at'],
                properties: [
                    new OA\Property(property: 'amount', type: 'number', format: 'float'),
                    new OA\Property(property: 'type', type: 'string', enum: ['income', 'expense']),
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'party_id', type: 'integer'),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'wallet_id', type: 'integer'),
                    new OA\Property(property: 'group_id', type: 'integer'),
                    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Transaction updated successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/Transaction')
            ),
            new OA\Response(
                response: 404,
                description: 'Transaction not found'
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            ),
        ]
    )]
    public function update(Request $request, $id): JsonResponse
    {
        $validationResult = $this->validateRequest($request, [
            'amount' => 'nullable|numeric|min:0.01',
            'type' => 'nullable|string|in:income,expense',
            'datetime' => ['nullable', new Iso8601DateTime],
            'description' => 'nullable|string',
            'party_id' => 'nullable|integer|exists:parties,id',
            'wallet_id' => 'nullable|integer|exists:wallets,id',
            'group_id' => 'nullable|integer|exists:groups,id',
            'categories' => 'nullable|array',
            'categories.*' => 'integer|exists:categories,id',
            'updated_at' => ['nullable', new Iso8601DateTime],
        ]);

        if (! $validationResult['isValidated']) {
            return $this->failure($validationResult['message'], $validationResult['code'], $validationResult['errors']);
        }

        $validatedData = $validationResult['data'];

        if (isset($validatedData['datetime'])) {
            $validatedData['datetime'] = format_iso8601_to_sql($validatedData['datetime']);
        }

        if (isset($validatedData['updated_at'])) {
            $validatedData['updated_at'] = format_iso8601_to_sql($validatedData['updated_at']);
        }

        /** @var Transaction */
        $transaction = Transaction::find($id);

        if (! $transaction) {
            return $this->failure('Transaction not found', 404);
        }

        try {
            $this->userCanAccessResource($transaction);
        } catch (HttpException $e) {
            return $this->failure($e->getMessage(), $e->getStatusCode());
        }

        $transaction->update(array_filter($validatedData, fn ($value) => $value !== null));
        $transaction->markAsSynced();

        if (isset($validatedData['categories'])) {
            $transaction->categories()->sync($validatedData['categories']);
        }

        return $this->success($transaction, 200);
    }

    #[OA\Delete(
        path: '/transactions/{id}',
        summary: 'Delete a specific transaction',
        tags: ['Transactions'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                description: 'ID of the transaction',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Transaction deleted successfully'
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            ),
            new OA\Response(
                response: 404,
                description: 'Transaction not found'
            ),
        ]
    )]
    public function destroy($id): JsonResponse
    {
        $transaction = Transaction::find($id);

        if (! $transaction) {
            return $this->failure('Transaction not found', 404);
        }

        try {
            $this->userCanAccessResource($transaction);
        } catch (HttpException $e) {
            return $this->failure($e->getMessage(), $e->getStatusCode());
        }

        $transaction->delete();

        return $this->success(['message' => 'Transaction deleted successfully']);
    }
}
