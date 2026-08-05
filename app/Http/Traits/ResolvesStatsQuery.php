<?php

namespace App\Http\Traits;

use App\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared parameter handling for anything driven by StatsService, so a statement
 * covers exactly the period and wallets the equivalent stats call would.
 */
trait ResolvesStatsQuery
{
    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveDateRange(Request $request): array
    {
        $endDate = Carbon::now()->endOfDay();
        $startDate = Carbon::now()->subDays(30)->startOfDay();

        if ($request->has('preset')) {
            $endDate = Carbon::now()->endOfDay();

            $startDate = match ($request->input('preset')) {
                'all_time' => Carbon::parse('2000-01-01')->startOfDay(),
                'current_week' => Carbon::now()->startOfWeek()->startOfDay(),
                'current_month' => Carbon::now()->startOfMonth()->startOfDay(),
                'last_3_months' => Carbon::now()->subMonths(3)->startOfDay(),
                default => Carbon::now()->subDays(30)->startOfDay(),
            };
        } else {
            if ($request->has('start_date')) {
                $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
            }
            if ($request->has('end_date')) {
                $endDate = Carbon::parse($request->input('end_date'))->endOfDay();
            }
        }

        return [$startDate, $endDate];
    }

    /**
     * @return array|JsonResponse
     */
    protected function resolveWalletIds(Request $request, $user): array|JsonResponse
    {
        if (! $request->has('wallet_ids')) {
            return [];
        }

        $walletIds = array_filter(array_map('intval', explode(',', $request->input('wallet_ids'))));

        $validWalletIds = Wallet::where('user_id', $user->id)
            ->whereIn('id', $walletIds)
            ->pluck('id')
            ->toArray();

        $invalidWalletIds = array_diff($walletIds, $validWalletIds);
        if (! empty($invalidWalletIds)) {
            return $this->failure(__('One or more wallet IDs are invalid or do not belong to the user.'), 422, [
                'invalid_wallet_ids' => array_values($invalidWalletIds),
            ]);
        }

        return $walletIds;
    }
}
