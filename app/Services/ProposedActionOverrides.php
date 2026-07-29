<?php

namespace App\Services;

use App\Models\User;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * What a user may change on a proposed action before confirming it, and what
 * still has to hold once they have.
 *
 * Both halves are one policy: the allowlist decides which fields survive the
 * merge, and the checks re-prove the things the tool proved at propose time but
 * an edit could have broken. Fields that establish ownership are absent from
 * the allowlist by design, so an edit can never repoint an action at another
 * user's record.
 */
class ProposedActionOverrides
{
    /**
     * Keys a user is allowed to override on confirm, per action type. Anything
     * else (notably user_id) is dropped before merging.
     *
     * @return array<int, string>
     */
    public function allowedKeys(string $actionType): array
    {
        return match ($actionType) {
            'transaction.create' => ['amount', 'type', 'wallet_id', 'party_id', 'description', 'datetime', 'categories'],
            'transaction.categorize' => ['categories'],
            'transfer.create' => ['amount', 'from_wallet_id', 'to_wallet_id', 'exchange_rate', 'datetime'],
            'wallet.create' => ['name', 'type', 'currency', 'description'],
            'category.create' => ['name', 'type', 'description'],
            'party.create' => ['name', 'type', 'description'],
            default => [],
        };
    }

    /**
     * Merge the caller's edits into a proposal, keeping only allowed keys.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function merge(string $actionType, array $payload, array $overrides): array
    {
        $allowed = array_flip($this->allowedKeys($actionType));

        return array_merge($payload, array_intersect_key($overrides, $allowed));
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws HttpException when an edit would make the action invalid or unauthorized.
     */
    public function revalidate(User $user, string $actionType, array $payload): void
    {
        if (in_array($actionType, ['transaction.create', 'transaction.categorize'], true)) {
            $this->checkTransaction($user, $payload);
        }

        match ($actionType) {
            'transfer.create' => $this->checkTransfer($user, $payload),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function checkTransaction(User $user, array $payload): void
    {
        app(TransactionWriter::class)->validateOwnership($user, $payload, $payload['categories'] ?? []);

        if (isset($payload['amount']) && (float) $payload['amount'] <= 0) {
            $this->reject('Amount must be greater than zero.');
        }
        if (isset($payload['type']) && ! in_array($payload['type'], ['income', 'expense'], true)) {
            $this->reject('Type must be income or expense.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function checkTransfer(User $user, array $payload): void
    {
        foreach (['from_wallet_id', 'to_wallet_id'] as $key) {
            if (! empty($payload[$key]) && ! $user->wallets()->whereKey($payload[$key])->exists()) {
                $this->deny('The selected wallet does not belong to you.');
            }
        }
        if (! empty($payload['from_wallet_id']) && $payload['from_wallet_id'] === ($payload['to_wallet_id'] ?? null)) {
            $this->reject('The source and destination wallets must be different.');
        }
        if (isset($payload['amount']) && (float) $payload['amount'] <= 0) {
            $this->reject('Amount must be greater than zero.');
        }
        if (isset($payload['exchange_rate']) && (float) $payload['exchange_rate'] <= 0) {
            $this->reject('Exchange rate must be greater than zero.');
        }
    }

    private function reject(string $message): never
    {
        throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, $message);
    }

    private function deny(string $message): never
    {
        throw new HttpException(Response::HTTP_FORBIDDEN, $message);
    }
}
