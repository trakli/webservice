<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

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
                in: 'query',
                description: 'Type of transaction (income/expense)',
                required: true,
                schema: new OA\Schema(type: 'string', enum: ['income', 'expense'])
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Number of transactions to fetch',
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

        if (! in_array($type, ['income', 'expense'])) {
            return $this->failure('Invalid transaction type', 400);
        }

        $transactions = Transaction::where('type', $type)->paginate($limit);

        return $this->success($transactions);
    }

    #[OA\Post(
        path: '/transactions',
        summary: 'Create a new transaction',
        tags: ['Transactions'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount', 'type'],
                properties: [
                    new OA\Property(property: 'amount', type: 'number', format: 'float'),
                    new OA\Property(property: 'type', type: 'string', enum: ['income', 'expense']),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'datetime', type: 'string', format: 'date'),
                    new OA\Property(property: 'party_id', type: 'integer'),
                    new OA\Property(property: 'wallet_id', type: 'integer'),
                    new OA\Property(property: 'group_id', type: 'integer'),
                    new OA\Property(property: 'categories', type: 'array', items: new OA\Items(type: 'integer', description: 'Category ID array')),
                ]
            )
        ),
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
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'type' => 'required|string|in:income,expense',
            'description' => 'nullable|string',
            'datetime' => 'nullable|date',
            'group_id' => 'nullable|integer|exists:groups,id',
            'party_id' => 'nullable|integer|exists:parties,id',
            'wallet_id' => 'nullable|integer|exists:wallets,id',
            'categories' => 'nullable|array',
            'categories.*' => 'integer|exists:categories,id',
        ]);

        if ($validator->fails()) {
            return $this->failure($validator->errors(), 400);
        }

        $request = $validator->validated();
        $categories = [];
        if (isset($request['categories'])) {
            $categories = $request['categories'];
            unset($request['categories']);
        }
        $transaction = Transaction::create($request);

        if (! empty($categories)) {
            $transaction->categories()->sync($categories);
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

        if (! in_array($type, ['income', 'expense'])) {
            return $this->failure('Invalid transaction type', 400);
        }

        $transaction = Transaction::where('type', $type)->find($id);

        if (! $transaction) {
            return $this->failure('Transaction not found', 404);
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
                required: ['date', 'party_id', 'wallet_id', 'amount', 'group_id'],
                properties: [
                    new OA\Property(property: 'amount', type: 'number', format: 'float'),
                    new OA\Property(property: 'type', type: 'string', enum: ['income', 'expense']),
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'party_id', type: 'integer'),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'wallet_id', type: 'integer'),
                    new OA\Property(property: 'group_id', type: 'integer'),
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
        $validator = Validator::make($request->all(), [
            'amount' => 'nullable|numeric|min:0.01',
            'type' => 'nullable|string|in:income,expense',
            'datetime' => 'nullable|date',
            'description' => 'nullable|string',
            'party_id' => 'nullable|integer|exists:parties,id',
            'wallet_id' => 'nullable|integer|exists:wallets,id',
            'group_id' => 'nullable|integer|exists:groups,id',
            'categories' => 'nullable|array',
            'categories.*' => 'integer|exists:categories,id',
        ]);

        if ($validator->fails()) {
            return $this->failure($validator->errors(), 400);
        }

        $validatedData = $validator->validated();
        $transaction = Transaction::find($id);

        if (! $transaction) {
            return $this->failure('Transaction not found', 404);
        }

        $transaction->update(array_filter($validatedData, fn ($value) => $value !== null));

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
                description: 'Transaction deleted successfully'
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
    public function destroy($id, Request $request): JsonResponse
    {
        $type = $request->query('type');

        if (! in_array($type, ['income', 'expense'])) {
            return $this->failure('Invalid transaction type', 400);
        }

        $transaction = Transaction::where('type', $type)->find($id);

        if (! $transaction) {
            return $this->failure('Transaction not found', 404);
        }

        $transaction->delete();

        return $this->success(['message' => 'Transaction deleted successfully']);
    }
}
