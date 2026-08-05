<?php

namespace App\Http\Traits;

use App\Enums\TransactionIntent;
use Illuminate\Http\Request;

/**
 * Shared query filtering for transaction listings. Listing and exporting must
 * read the same parameters the same way, or a downloaded statement stops
 * matching the rows the user is looking at.
 */
trait FiltersTransactions
{
    /**
     * Apply optional filtering query parameters (date range, wallets,
     * categories, search) to the given transaction query.
     */
    protected function applyTransactionFilters($query, Request $request): void
    {
        if ($request->filled('date_from')) {
            $query->whereDate('datetime', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('datetime', '<=', $request->query('date_to'));
        }

        $walletIds = $this->listParam($request, 'wallet_ids');
        if (! empty($walletIds)) {
            $query->whereIn('wallet_id', $walletIds);
        }

        $categoryIds = $this->listParam($request, 'category_ids');
        if (! empty($categoryIds)) {
            $query->whereHas('categories', function ($q) use ($categoryIds) {
                $q->whereIn('categories.id', $categoryIds);
            });
        }

        $this->applyIntentFilters($query, $request);

        if ($request->filled('search')) {
            $this->applySearchFilter($query, (string) $request->query('search'));
        }
    }

    protected function applyIntentFilters($query, Request $request): void
    {
        $intents = array_values(array_intersect(
            $this->listParam($request, 'intent'),
            TransactionIntent::values()
        ));
        if (! empty($intents)) {
            $query->whereIn('intent', $intents);
        }

        if ($request->boolean('exclude_transfers')) {
            $query->nonTransfer();
        }
    }

    /**
     * Parse a list query parameter that may arrive either as an array
     * (key[]=a&key[]=b) or a comma-separated string (key=a,b), returning a
     * trimmed list with empty entries removed.
     */
    protected function listParam(Request $request, string $key): array
    {
        $value = $request->query($key);
        if ($value === null || $value === '') {
            return [];
        }

        $items = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(
            array_map('trim', $items),
            fn ($item) => $item !== ''
        ));
    }

    /**
     * Apply a free-text search filter that matches against the description
     * and, when the query contains a number, also the exact amount.
     */
    protected function applySearchFilter($query, string $search): void
    {
        $query->where(function ($q) use ($search) {
            $q->where('description', 'LIKE', '%' . $search . '%');

            $numeric = preg_replace('/[^0-9.]/', '', $search);
            if ($numeric !== '' && is_numeric($numeric)) {
                $q->orWhere('amount', $numeric);
            }
        });
    }
}
