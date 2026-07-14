<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Thin adapter the agent's stats-backed tools call. Resolves the same defaults
 * the stats endpoint uses (user currency, all wallets, a window) and returns a
 * single computed section, so chart/kpi widgets reuse authoritative numbers
 * instead of the model inventing them.
 */
class StatsToolService
{
    public function __construct(protected StatsService $stats)
    {
    }

    /**
     * @param  array<int, int>  $walletIds
     * @return array<string, mixed>
     */
    public function section(
        User $user,
        string $section,
        ?string $period = null,
        ?string $startDate = null,
        ?string $endDate = null,
        array $walletIds = []
    ): array {
        $start = $startDate !== null
            ? Carbon::parse($startDate)->startOfDay()
            : Carbon::now()->subDays(30)->startOfDay();

        $end = $endDate !== null
            ? Carbon::parse($endDate)->endOfDay()
            : Carbon::now()->endOfDay();

        $currency = $user->getConfigValue('default-currency') ?? 'USD';

        if (empty($walletIds)) {
            $walletIds = $user->wallets()->pluck('id')->all();
        }

        return $this->stats->compute(
            $user,
            $start,
            $end,
            $walletIds,
            $period ?? 'month',
            $currency,
            $section
        );
    }
}
