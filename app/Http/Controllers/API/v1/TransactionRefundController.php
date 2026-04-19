<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Http\Traits\ApiQueryable;
use App\Models\Refund;
use App\Models\Transaction;
use App\Rules\ValidateClientId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Marks and unmarks transactions as refunds. Kept separate from
 * TransactionController so the main CRUD class stays under the project's
 * complexity budget.
 */
#[OA\Tag(name: 'Refund', description: 'Mark income transactions as refunds')]
class TransactionRefundController extends ApiController
{
    use ApiQueryable;

    /**
     * Mark an income transaction as a refund, optionally linking it to
     * the original expense it refunded. Only income transactions can be
     * refunds; the original (if provided) must be an expense owned by
     * the same user.
     */
    #[OA\Post(
        path: '/transactions/{id}/refund',
        summary: 'Mark an income transaction as a refund',
        tags: ['Refund'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'client_id',
                        description: 'Client-generated ID for the new refund row',
                        type: 'string',
                        nullable: true
                    ),
                    new OA\Property(
                        property: 'original_transaction_id',
                        description: 'Server id of the expense being refunded',
                        type: 'integer',
                        nullable: true
                    ),
                    new OA\Property(
                        property: 'original_client_id',
                        description: 'Client id of the expense being refunded (used when original has not synced yet)',
                        type: 'string',
                        nullable: true
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Refund marked',
                content: new OA\JsonContent(ref: '#/components/schemas/Refund')
            ),
            new OA\Response(response: 404, description: 'Transaction not found'),
            new OA\Response(response: 422, description: 'Only income transactions can be refunds, or invalid original'),
        ]
    )]
    public function mark(Request $request, $transactionId): JsonResponse
    {
        $user = $request->user();
        $transaction = Transaction::find($transactionId);

        if (! $transaction || $transaction->user_id !== $user->id) {
            return $this->failure(__('Transaction not found'), 404);
        }

        if ($transaction->type !== 'income') {
            return $this->failure(__('Only income transactions can be marked as refunds'), 422);
        }

        $data = $request->validate([
            'client_id' => ['nullable', 'string', new ValidateClientId()],
            'original_transaction_id' => 'nullable|integer',
            'original_client_id' => ['nullable', 'string', new ValidateClientId()],
        ]);

        $original = null;
        if (! empty($data['original_transaction_id'])) {
            $original = Transaction::find($data['original_transaction_id']);
        } elseif (! empty($data['original_client_id'])) {
            $original = Transaction::findByClientId($data['original_client_id'], $user);
        }

        if ($original && ($original->user_id !== $user->id || $original->type !== 'expense')) {
            return $this->failure(__('Invalid original transaction'), 422);
        }

        $refund = $transaction->markAsRefund($original);

        if (! empty($data['client_id'])) {
            $refund->setClientGeneratedId($data['client_id'], $user);
        }
        $refund->markAsSynced();
        $refund->refresh();

        return $this->success($refund, __('Transaction marked as refund'));
    }

    #[OA\Get(
        path: '/refunds',
        summary: 'List refunds (paginated, supports sync filters)',
        tags: ['Refund'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/limitParam'),
            new OA\Parameter(ref: '#/components/parameters/syncedSinceParam'),
            new OA\Parameter(ref: '#/components/parameters/noClientIdParam'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Refunds visible to the user',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Refund')
                        ),
                    ],
                    type: 'object'
                )
            ),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Refund::query()
            ->whereHas('refundTransaction', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            });

        try {
            $data = $this->applyApiQuery($request, $query);

            return $this->success($data);
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e->getMessage(), 422);
        }
    }

    #[OA\Delete(
        path: '/transactions/{id}/refund',
        summary: 'Remove the refund flag from a transaction',
        tags: ['Refund'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Refund flag removed'),
            new OA\Response(response: 404, description: 'Transaction not found'),
        ]
    )]
    public function unmark(Request $request, $transactionId): JsonResponse
    {
        $user = $request->user();
        $transaction = Transaction::find($transactionId);

        if (! $transaction || $transaction->user_id !== $user->id) {
            return $this->failure(__('Transaction not found'), 404);
        }

        $transaction->unmarkRefund();

        return $this->success(null, __('Refund flag removed'), 204);
    }
}
