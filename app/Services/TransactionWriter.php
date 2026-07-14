<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Shared, owner-scoped transaction write path. Both the HTTP controller and the
 * AI agent route their creates through here so validation, ownership scoping and
 * Syncable bookkeeping stay identical regardless of the caller.
 *
 * Callers are responsible for wrapping calls in a database transaction when they
 * need atomicity across additional work (file uploads, recurring rules).
 */
class TransactionWriter
{
    /**
     * Ensure the user owns every resource referenced by the payload.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $categories
     *
     * @throws HttpException When any referenced resource is not owned by the user.
     */
    public function validateOwnership(User $user, array $data, array $categories = []): void
    {
        if (! empty($data['wallet_id']) && ! $user->wallets()->where('id', $data['wallet_id'])->exists()) {
            throw new HttpException(403, 'The selected wallet does not belong to user');
        }

        if (! empty($data['group_id']) && ! $user->groups()->where('id', $data['group_id'])->exists()) {
            throw new HttpException(403, 'The selected group does not belong to user');
        }

        if (! empty($data['party_id']) && ! $user->parties()->where('id', $data['party_id'])->exists()) {
            throw new HttpException(403, 'The selected party does not belong to user');
        }

        if (! empty($categories)) {
            $userCategoryIds = $user->categories()->pluck('id')->toArray();
            $invalidCategories = array_diff($categories, $userCategoryIds);
            if (! empty($invalidCategories)) {
                throw new HttpException(
                    403,
                    'Some of the selected categories do not belong to user. Invalid category IDs: ' .
                    implode(',', $invalidCategories)
                );
            }
        }
    }

    /**
     * Persist a new transaction for the user with its categories and group, and
     * record sync state. Does not handle file uploads or recurring rules; the
     * caller composes those around this call inside its own transaction.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $categories
     */
    public function createCore(User $user, array $data, array $categories = []): Transaction
    {
        /** @var Transaction $transaction */
        $transaction = $user->transactions()->create($data);

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

        return $transaction;
    }
}
