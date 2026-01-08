<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Http\Traits\ApiQueryable;
use App\Jobs\RecurrentTransactionJob;
use App\Models\RecurringTransactionRule;
use App\Models\Transaction;
use App\Rules\Iso8601DateTime;
use App\Rules\ValidateClientId;
use App\Services\FileService;
use App\Services\RecurringTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

#[OA\Tag(name: 'Transactions', description: 'Endpoints for managing transactions')]
class TransactionController extends ApiController
{
    use ApiQueryable;

    private RecurringTransactionService $recurring_transaction_service;

    public function __construct(RecurringTransactionService $recurring_transaction_service)
    {
        $this->recurring_transaction_service = $recurring_transaction_service;
    }

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
                            items: new OA\Items(ref: '#/components/schemas/Transaction')
                        ),
                    ],
                    type: 'object'
                )
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

        if (! empty($type) && ! in_array($type, ['income', 'expense'])) {
            return $this->failure(__('Invalid transaction type'), 400);
        }

        /** @var \App\Models\User $user */
        $user = auth()->user();
        $query = $user->transactions();
        if (! empty($type)) {
            $query->where('type', $type);
        }

        try {
            $data = $this->applyApiQuery($request, $query);

            return $this->success($data);
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/transactions/{id}/files',
        summary: 'Add file to a transaction',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['files', 'type'],
                properties: [
                    new OA\Property(
                        property: 'files',
                        description: 'Files to attach to this transaction',
                        type: 'array',
                        items: new OA\Items(type: 'string', format: 'binary')
                    ),
                ]
            )
        ),
        tags: ['Transactions'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Transaction updated successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/Transaction')
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            ),
        ]
    )]
    public function uploadFiles(Request $request, $id)
    {
        $request->validate([
            'files' => 'nullable|array',
            'files.*' => 'file|mimes:jpg,jpeg,png,pdf|max:1024',
        ]);
        $transaction = $request->user()->transactions()->find($id);

        if (! $transaction) {
            return $this->failure(__('Transaction not found'), 404);
        }

        try {
            DB::transaction(function () use ($request, $transaction) {
                FileService::uploadFiles($transaction, $request, 'files', 'transactions');
            });

        } catch (Throwable $e) {
            logger()->error('Transaction file upload error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return $this->failure(__('Failed to upload files'), 500, [$e->getMessage()]);
        }
        $transaction->refresh();

        return $this->success($transaction, statusCode: 200);
    }

    #[OA\Post(
        path: '/transactions',
        summary: 'Create a new transaction',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount', 'type'],
                properties: [
                    new OA\Property(property: 'client_id', description: 'Unique identifier for your local client', type: 'string',
                        format: 'string', example: '245cb3df-df3a-428b-a908-e5f74b8d58a3:245cb3df-df3a-428b-a908-e5f74b8d58a4'),
                    new OA\Property(property: 'amount', type: 'number', format: 'float'),
                    new OA\Property(property: 'type', type: 'string', enum: ['income', 'expense']),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'datetime', type: 'string', format: 'datetime'),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'party_id', type: 'integer'),
                    new OA\Property(property: 'wallet_id', type: 'integer'),
                    new OA\Property(property: 'group_id', type: 'integer'),
                    new OA\Property(property: 'is_recurring', description: 'Set the transaction as a recurring transaction', type: 'boolean'),
                    new OA\Property(property: 'recurrence_period', description: 'Set how often the transaction should repeat', type: 'string'),
                    new OA\Property(property: 'recurrence_interval', description: 'Set how often the transaction should repeat', type: 'integer'),
                    new OA\Property(property: 'recurrence_ends_at', description: 'When the transaction stops repeating', type: 'date-time'),
                    new OA\Property(property: 'categories', type: 'array', items: new OA\Items(description: 'Category ID array', type: 'integer')),
                    new OA\Property(
                        property: 'files',
                        description: 'Files to attach to this transaction',
                        type: 'array',
                        items: new OA\Items(type: 'string', format: 'binary')
                    )]
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
            'client_id' => ['nullable', 'string', new ValidateClientId],
            'amount' => 'required|numeric|min:0.01',
            'type' => 'required|string|in:income,expense',
            'description' => 'nullable|string',
            'datetime' => ['nullable', new Iso8601DateTime],
            'created_at' => ['nullable', new Iso8601DateTime],
            'group_id' => 'nullable|integer|exists:groups,id',
            'party_id' => 'nullable|integer|exists:parties,id',
            'wallet_id' => 'required|integer|exists:wallets,id',
            'categories' => 'nullable|array',
            'is_recurring' => 'nullable|boolean',
            'recurrence_period' => 'nullable|string|in:daily,weekly,monthly,yearly',
            'recurrence_interval' => 'nullable|integer|min:1',
            'recurrence_ends_at' => ['nullable', 'date', 'after:today', new Iso8601DateTime],
            'categories.*' => 'integer|exists:categories,id',
            'files' => 'nullable|array',
            'files.*' => 'file|mimes:jpg,jpeg,png,pdf|max:1240',
        ]);

        if (! $validationResult['isValidated']) {
            return $this->failure($validationResult['message'], $validationResult['code'], $validationResult['errors']);
        }

        $data = $validationResult['data'];
        $recurring_transaction_data = [];

        if (isset($data['is_recurring']) && $data['is_recurring']) {
            $result = $this->validateRequest($request, [
                'recurrence_period' => 'required',
            ]);
            if (! $result['isValidated']) {
                return $this->failure($result['message'], $result['code'], $result['errors']);
            }

            $recurring_transaction_data['recurrence_period'] = $data['recurrence_period'];
            $recurring_transaction_data['recurrence_interval'] = $data['recurrence_interval'] ?? null;
            $recurring_transaction_data['recurrence_ends_at'] = $data['recurrence_ends_at'] ?? null;
        }
        // remove recurring transaction data from $data
        unset($data['recurrence_ends_at']);
        unset($data['recurrence_interval']);
        unset($data['recurrence_period']);
        unset($data['is_recurring']);

        if (isset($recurring_transaction_data['recurrence_ends_at'])) {
            $recurring_transaction_data['recurrence_ends_at'] = format_iso8601_to_sql($recurring_transaction_data['recurrence_ends_at']);
        }

        if (isset($data['datetime'])) {
            $data['datetime'] = format_iso8601_to_sql($data['datetime']);
        }

        if (isset($data['created_at'])) {
            $data['created_at'] = format_iso8601_to_sql($data['created_at']);
        }

        $categories = [];
        if (isset($data['categories'])) {
            $categories = $data['categories'];
            unset($data['categories']);
        }

        try {
            // Validate ownership of all resources before creating the transaction
            $this->validateResourceOwnership($data, $categories);

            $transaction = DB::transaction(function () use ($request, $data, $categories, $recurring_transaction_data) {
                /** @var Transaction $transaction */
                $transaction = auth()->user()->transactions()->create($data);

                $user = $request->user();
                if (isset($data['client_id'])) {
                    $transaction->setClientGeneratedId($data['client_id'], $user);
                }

                $transaction->markAsSynced();

                if (! empty($categories)) {
                    $transaction->categories()->sync($categories);
                }

                if (isset($data['group_id'])) {
                    $transaction->groups()->sync($data['group_id']);
                }

                if ($request->hasFile('files')) {
                    foreach ($request->file('files') as $file) {
                        $path = $file->store('transactions');
                        $transaction->files()->create([
                            'path' => $path,
                            'type' => 'file',
                        ]);
                    }
                }

                // check if this transaction is recurring
                if (! empty($recurring_transaction_data)) {
                    // Create recurring transaction
                    $recurring_transaction = new RecurringTransactionRule($recurring_transaction_data);
                    $recurring_transaction->next_scheduled_at = $this->recurring_transaction_service->getNextScheduleDate($recurring_transaction);
                    $recurring_transaction->transaction_id = $transaction->id;
                    $recurring_transaction->save();

                    RecurrentTransactionJob::dispatch(
                        (int) $recurring_transaction->id
                    )->delay($recurring_transaction->next_scheduled_at);
                }

                return $transaction;
            });
        } catch (HttpException $e) {
            return $this->failure($e->getMessage(), $e->getStatusCode());
        } catch (Throwable $e) {
            logger()->error('Transaction creation error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return $this->failure(__('Failed to create transaction'), 500, [$e->getMessage()]);
        }

        return $this->success($transaction, statusCode: 201);
    }

    #[OA\Delete(
        path: '/transactions/{id}/files/{file_id}',
        summary: 'Removes a file from a transaction',
        tags: ['Transactions'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Transaction deleted successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/Transaction')
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            ),
        ]
    )]
    public function deleteFiles(Request $request, $transaction_id, $file_id): JsonResponse
    {
        $transaction = $request->user()->transactions()->find($transaction_id);

        if (! $transaction) {
            return $this->failure(__('Transaction not found'), 404);
        }

        $file = $transaction->files()->find($file_id);

        if (! $file) {
            return $this->failure(__('File not found'), 404);
        }

        $file->delete();

        $transaction->refresh();

        return $this->success($transaction, statusCode: 200);
    }

    #[OA\Get(
        path: '/transactions/{id}',
        summary: 'Get a specific transaction',
        tags: ['Transactions'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'ID of the transaction',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'type',
                description: 'Type of transaction (income/expense)',
                in: 'query',
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
            return $this->failure(__('Invalid transaction type'), 400);
        }

        $query = Transaction::query();
        if (! empty($type)) {
            $query->where('type', $type);
        }

        $transaction = $query->find($id);

        if (! $transaction) {
            return $this->failure(__('Transaction not found'), 404);
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
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount', 'updated_at'],
                properties: [
                    new OA\Property(property: 'client_id', description: 'Unique identifier for your local client', type: 'string'),
                    new OA\Property(property: 'amount', type: 'number', format: 'float'),
                    new OA\Property(property: 'type', type: 'string', enum: ['income', 'expense']),
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'party_id', type: 'integer'),
                    new OA\Property(property: 'group_id', type: 'integer'),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'wallet_id', type: 'integer'),
                    new OA\Property(property: 'is_recurring', description: 'Set the transaction as a recurring transaction', type: 'boolean'),
                    new OA\Property(property: 'recurrence_period', description: 'Set how often the transaction should repeat', type: 'string'),
                    new OA\Property(property: 'recurrence_interval', description: 'Set how often the transaction should repeat', type: 'integer'),
                    new OA\Property(property: 'recurrence_ends_at', description: 'When the transaction stops repeating', type: 'date-time'),
                    new OA\Property(property: 'categories', type: 'array', items: new OA\Items(description: 'Category ID array', type: 'integer')),
                    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
                ]
            )
        ),
        tags: ['Transactions'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'ID of the transaction',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'type',
                description: 'Type of transaction (income/expense)',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', enum: ['income', 'expense'])
            ),
        ],
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
            'client_id' => ['nullable', 'string', new ValidateClientId],
            'amount' => 'nullable|numeric|min:0.01',
            'type' => 'nullable|string|in:income,expense',
            'datetime' => ['nullable', new Iso8601DateTime],
            'description' => 'nullable|string',
            'party_id' => 'nullable|integer|exists:parties,id',
            'wallet_id' => 'sometimes|integer|exists:wallets,id',
            'group_id' => 'nullable|integer|exists:groups,id',
            'categories' => 'nullable|array',
            'categories.*' => 'integer|exists:categories,id',
            'is_recurring' => 'nullable|boolean',
            'recurrence_period' => 'nullable|string|in:daily,weekly,monthly,yearly',
            'recurrence_interval' => 'nullable|integer|min:1',
            'recurrence_ends_at' => ['nullable', 'date', 'after:today', new Iso8601DateTime],
            'updated_at' => ['nullable', new Iso8601DateTime],
        ]);

        if (! $validationResult['isValidated']) {
            return $this->failure($validationResult['message'], $validationResult['code'], $validationResult['errors']);
        }

        $validatedData = $validationResult['data'];
        $recurring_transaction_data = [];

        if (isset($validatedData['is_recurring']) && $validatedData['is_recurring']) {
            $result = $this->validateRequest($request, [
                'recurrence_period' => 'required',
            ]);
            if (! $result['isValidated']) {
                return $this->failure($result['message'], $result['code'], $result['errors']);
            }

            $recurring_transaction_data['recurrence_period'] = $validatedData['recurrence_period'];
            $recurring_transaction_data['recurrence_interval'] = $validatedData['recurrence_interval'] ?? null;
            $recurring_transaction_data['recurrence_ends_at'] = $validatedData['recurrence_ends_at'] ?? null;
        }

        // remove recurring transaction data from $data
        unset($validatedData['recurrence_ends_at']);
        unset($validatedData['recurrence_interval']);
        unset($validatedData['recurrence_period']);
        unset($validatedData['is_recurring']);

        if (isset($recurring_transaction_data['recurrence_ends_at'])) {
            $recurring_transaction_data['recurrence_ends_at'] = format_iso8601_to_sql($recurring_transaction_data['recurrence_ends_at']);
        }

        if (isset($validatedData['datetime'])) {
            $validatedData['datetime'] = format_iso8601_to_sql($validatedData['datetime']);
        }

        if (isset($validatedData['updated_at'])) {
            $validatedData['updated_at'] = format_iso8601_to_sql($validatedData['updated_at']);
        }

        /** @var Transaction */
        $transaction = Transaction::find($id);

        if (! $transaction) {
            return $this->failure(__('Transaction not found'), 404);
        }

        try {
            $this->userCanAccessResource($transaction);
        } catch (HttpException $e) {
            return $this->failure($e->getMessage(), $e->getStatusCode());
        }

        // Extract categories before validation
        $categories = $validatedData['categories'] ?? [];

        try {
            // Validate ownership of all resources before updating the transaction
            $this->validateResourceOwnership($validatedData, $categories);
            $this->checkUpdatedAt($transaction, $validatedData);

            $transaction = DB::transaction(function () use ($validatedData, $transaction, $recurring_transaction_data, $request) {

                $transaction->update(array_filter($validatedData, fn ($value) => $value !== null));
                $transaction->markAsSynced();

                if (isset($validatedData['categories'])) {
                    $transaction->categories()->sync($validatedData['categories']);
                }

                if (isset($validatedData['group_id'])) {
                    $transaction->groups()->sync([$validatedData['group_id']]);
                }

                $user = $request->user();
                if (isset($request['client_id']) && ! $transaction->client_id) {
                    $transaction->setClientGeneratedId($request['client_id'], $user);
                }

                $recurring_transaction = $transaction->recurring_transaction_rule()->first();

                // check if this transaction is recurring
                if (empty($recurring_transaction_data)) {
                    if (! is_null($recurring_transaction)) {
                        $recurring_transaction->delete();
                    }
                } else {

                    $schedule_job = true;

                    if (is_null($recurring_transaction)) {
                        $recurring_transaction = new RecurringTransactionRule($recurring_transaction_data); // create temporay instance from data array
                        $recurring_transaction->next_scheduled_at = $this->recurring_transaction_service->getNextScheduleDate($recurring_transaction);
                        $recurring_transaction->transaction_id = $transaction->id;
                        $recurring_transaction->save();
                    } else {
                        // Check if details have changed. If not, do not reschedule the job
                        if (($recurring_transaction_data['recurrence_period'] == $recurring_transaction->recurrence_period)
                            &&
                            ($recurring_transaction_data['recurrence_interval'] == $recurring_transaction->recurrence_interval)) {
                            $schedule_job = false;
                        } else {
                            $recurring_transaction_data['next_scheduled_at'] = $this->recurring_transaction_service->getNextScheduleDate($recurring_transaction);
                        }
                        $recurring_transaction->update($recurring_transaction_data);

                    }
                    if ($schedule_job) {
                        RecurrentTransactionJob::dispatch(
                            (int) $recurring_transaction->id
                        )->delay($recurring_transaction->next_scheduled_at);
                    }
                }

                return $transaction;
            });

            return $this->success($transaction, 200);
        } catch (HttpException $e) {
            return $this->failure($e->getMessage(), $e->getStatusCode());
        } catch (Throwable $e) {
            logger()->error('Transaction update error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return $this->failure(__('Failed to update transaction'), 500, [$e->getMessage()]);
        }

    }

    #[OA\Delete(
        path: '/transactions/{id}',
        summary: 'Delete a specific transaction',
        tags: ['Transactions'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'ID of the transaction',
                in: 'path',
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
            return $this->failure(__('Transaction not found'), 404);
        }

        try {
            $this->userCanAccessResource($transaction);
        } catch (HttpException $e) {
            return $this->failure($e->getMessage(), $e->getStatusCode());
        }

        $transaction->delete();

        return $this->success(['message' => __('Transaction deleted successfully')]);
    }

    /**
     * Validate that the authenticated user owns the specified resources.
     *
     * @param  array  $data  The validated data containing resource IDs
     * @param  array  $categories  The category IDs array
     *
     * @throws HttpException If any resource does not belong to the user
     */
    private function validateResourceOwnership(array $data, array $categories = []): void
    {
        $user = auth()->user();

        // Check wallet ownership
        if (! empty($data['wallet_id']) && ! $user->wallets()->where('id', $data['wallet_id'])->exists()) {
            throw new HttpException(403, 'The selected wallet does not belong to user');
        }

        // Check group ownership
        if (! empty($data['group_id']) && ! $user->groups()->where('id', $data['group_id'])->exists()) {
            throw new HttpException(403, 'The selected group does not belong to user');
        }

        // Check party ownership
        if (! empty($data['party_id']) && ! $user->parties()->where('id', $data['party_id'])->exists()) {
            throw new HttpException(403, 'The selected party does not belong to user');
        }

        // Check categories ownership
        if (! empty($categories)) {
            $user_category_ids = $user->categories()->pluck('id')->toArray();
            $invalid_categories = array_diff($categories, $user_category_ids);
            if (! empty($invalid_categories)) {
                throw new HttpException(403, 'Some of the selected categories do not belong to user. Invalid category IDs: '.implode(',', $invalid_categories));
            }
        }
    }
}
