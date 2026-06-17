<?php

namespace App\Ai\Tools\Write;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

/**
 * Helpers that turn the names a user speaks ("Cash", "Groceries") into the
 * owned resource ids a write needs, scoped to the acting user. Resolution is
 * case-insensitive and never crosses tenant boundaries.
 */
trait ResolvesUserResources
{
    protected function resolveWalletId(Authenticatable $user, array $arguments): int
    {
        if (! empty($arguments['wallet_id'])) {
            $walletId = (int) $arguments['wallet_id'];
            if (! $user->wallets()->whereKey($walletId)->exists()) {
                throw new InvalidArgumentException('That wallet does not belong to you.');
            }

            return $walletId;
        }

        $name = trim((string) ($arguments['wallet_name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('A wallet is required. Ask the user which wallet to use.');
        }

        $wallet = $user->wallets()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if ($wallet === null) {
            throw new InvalidArgumentException("No wallet named \"{$name}\" was found. Confirm the wallet with the user or create it first.");
        }

        return (int) $wallet->id;
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, int>
     */
    protected function resolveCategoryIds(Authenticatable $user, array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $category = $user->categories()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            if ($category === null) {
                throw new InvalidArgumentException("No category named \"{$name}\" was found.");
            }
            $ids[] = (int) $category->id;
        }

        return $ids;
    }
}
