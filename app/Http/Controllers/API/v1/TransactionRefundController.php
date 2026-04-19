<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Marks and unmarks transactions as refunds. Kept separate from
 * TransactionController so the main CRUD class stays under the project's
 * complexity budget.
 */
class TransactionRefundController extends ApiController
{
    /**
     * Mark an income transaction as a refund, optionally linking it to
     * the original expense it refunded. Only income transactions can be
     * refunds; the original (if provided) must be an expense owned by
     * the same user.
     */
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
            'original_transaction_id' => 'nullable|integer',
        ]);

        $original = null;
        if (! empty($data['original_transaction_id'])) {
            $original = Transaction::find($data['original_transaction_id']);
            if (! $original || $original->user_id !== $user->id || $original->type !== 'expense') {
                return $this->failure(__('Invalid original transaction'), 422);
            }
        }

        $refund = $transaction->markAsRefund($original);

        return $this->success($refund, __('Transaction marked as refund'));
    }

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
