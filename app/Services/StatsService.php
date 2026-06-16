<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class StatsService
{
    private $user;

    private Carbon $startDate;

    private Carbon $endDate;

    private array $walletIds;

    private string $targetCurrency;

    public function __construct(
        protected ExchangeRateService $exchangeRateService
    ) {
    }

    /**
     * Independently computable stats sections, in rough cost order. A request
     * for a single section computes only that section so the client can load
     * the dashboard progressively instead of waiting for the whole payload.
     */
    public const SECTIONS = ['overview', 'activity', 'comparisons', 'categories', 'parties', 'cashflow'];

    /**
     * Compute stats for the given parameters. When $section is null the full
     * payload is returned; otherwise only that section is computed.
     */
    public function compute(
        $user,
        Carbon $startDate,
        Carbon $endDate,
        array $walletIds,
        string $period,
        string $targetCurrency,
        ?string $section = null
    ): array {
        $this->user = $user;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->walletIds = $walletIds;
        $this->targetCurrency = $targetCurrency;

        $result = ['currency' => $targetCurrency];
        $charts = [];

        foreach ($section === null ? self::SECTIONS : [$section] as $name) {
            [$fields, $sectionCharts] = $this->sectionData($name, $period);
            $result += $fields;
            $charts += $sectionCharts;
        }

        if ($charts !== [] || $section === null) {
            $result['charts'] = $charts;
        }

        return $result;
    }

    /**
     * Build one section's response fields and chart contributions. Returns
     * [topLevelFields, chartContributions].
     */
    private function sectionData(string $section, string $period): array
    {
        return match ($section) {
            'overview' => [$this->overviewFields(), []],
            'activity' => [['activity' => $this->getActivityMetrics()], []],
            'comparisons' => [['comparisons' => $this->getComparisons()], []],
            'categories' => [
                [
                    'top_categories' => $this->getTopCategories(),
                    'category_distribution' => $this->getCategoryDistribution(),
                ],
                [
                    'category_spending' => $this->getCategorySpendingData('expense'),
                    'income_sources' => $this->getCategorySpendingData('income'),
                ],
            ],
            'parties' => [
                [],
                [
                    'party_spending' => $this->getPartyData('expense'),
                    'party_income' => $this->getPartyData('income'),
                ],
            ],
            'cashflow' => [
                [
                    'largest_transactions' => $this->getLargestTransactions(),
                    'spending_trends' => $this->getSpendingTrends($period),
                ],
                [
                    'monthly_cash_flow' => $this->getMonthlyCashFlowData(),
                    'expense_by_wallet' => $this->getExpenseByWalletData(),
                ],
            ],
            default => [[], []],
        };
    }

    /**
     * Headline totals and the period summary that drive the KPI widgets.
     */
    private function overviewFields(): array
    {
        $periodTotals = $this->getPeriodTotals();
        $totalBalance = $this->getTotalBalance();

        $overview = [
            'total_balance' => $totalBalance,
            'net_worth' => $totalBalance,
            'total_income' => $periodTotals['income'],
            'total_expenses' => $periodTotals['expense'],
            'net_cash_flow' => $periodTotals['income'] - $periodTotals['expense'],
            'avg_monthly_income' => $this->getAverageMonthlyAmount('income'),
            'avg_monthly_expenses' => $this->getAverageMonthlyAmount('expense'),
            'savings_rate' => 0,
        ];

        if ($overview['total_income'] > 0) {
            $overview['savings_rate'] =
                (($overview['total_income'] - $overview['total_expenses']) / $overview['total_income']) * 100;
        }

        return ['overview' => $overview, 'period_summary' => $this->getPeriodSummary()];
    }

    /**
     * Invalidate all stats cache for a user.
     */
    public static function invalidateUserCache(int $userId): void
    {
        $pattern = 'stats:user:' . $userId . ':*';

        if (config('cache.default') === 'redis') {
            $redis = Cache::getRedis();
            $prefix = config('cache.prefix', 'laravel_cache');
            $keys = $redis->keys("{$prefix}:{$pattern}");
            foreach ($keys as $key) {
                $redis->del($key);
            }
        } else {
            Cache::increment('stats:user:' . $userId . ':version');
        }
    }

    /**
     * Generate a deterministic cache key for stats.
     */
    public static function generateCacheKey(
        int $userId,
        Carbon $startDate,
        Carbon $endDate,
        array $walletIds,
        string $period,
        ?string $section = null
    ): string {
        $version = Cache::get('stats:user:' . $userId . ':version', 1);

        $params = [
            'start' => $startDate->toDateString(),
            'end' => $endDate->toDateString(),
            'wallets' => implode(',', $walletIds),
            'period' => $period,
            'section' => $section ?? 'all',
            'v' => $version,
        ];

        return 'stats:user:' . $userId . ':' . md5(json_encode($params));
    }

    // -- Base query helpers --

    /**
     * Base query with wallet join for currency access.
     */
    private function baseJoinedQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = Transaction::join('wallets', 'transactions.wallet_id', '=', 'wallets.id')
            ->where('transactions.user_id', $this->user->id)
            ->nonTransfer()
            ->whereBetween('transactions.datetime', [$this->startDate, $this->endDate])
            ->select('transactions.*', 'wallets.currency');

        if (! empty($this->walletIds)) {
            $query->whereIn('transactions.wallet_id', $this->walletIds);
        }

        return $query;
    }

    /**
     * Base query with eager-loaded relationships.
     */
    private function baseEagerQuery(array $relations = ['wallet']): \Illuminate\Database\Eloquent\Builder
    {
        $query = Transaction::with($relations)
            ->where('user_id', $this->user->id)
            ->nonTransfer()
            ->whereBetween('datetime', [$this->startDate, $this->endDate]);

        if (! empty($this->walletIds)) {
            $query->whereIn('wallet_id', $this->walletIds);
        }

        return $query;
    }

    /**
     * Base query with table-qualified columns for joins.
     */
    private function baseQualifiedQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = Transaction::where('transactions.user_id', $this->user->id)
            ->nonTransfer()
            ->whereBetween('transactions.datetime', [$this->startDate, $this->endDate]);

        if (! empty($this->walletIds)) {
            $query->whereIn('transactions.wallet_id', $this->walletIds);
        }

        return $query;
    }

    // -- Currency conversion --

    /**
     * @throws RuntimeException When currency conversion fails
     */
    private function convertCurrency(float $amount, string $fromCurrency): float
    {
        if ($fromCurrency === $this->targetCurrency) {
            return $amount;
        }

        $converted = $this->exchangeRateService->convert(
            $amount,
            $fromCurrency,
            $this->targetCurrency,
            $this->user
        );

        if ($converted === null) {
            throw new RuntimeException(
                "Failed to convert {$fromCurrency} to {$this->targetCurrency}. Exchange rate unavailable."
            );
        }

        return $converted;
    }

    // -- Computation methods --

    private function getTotalBalance(): float
    {
        $query = Wallet::where('user_id', $this->user->id);

        if (! empty($this->walletIds)) {
            $query->whereIn('id', $this->walletIds);
        }

        $wallets = $query->get(['balance', 'currency']);
        $total = 0.0;

        foreach ($wallets as $wallet) {
            $total += $this->convertCurrency((float) $wallet->balance, $wallet->currency);
        }

        return $total;
    }

    private function getAverageMonthlyAmount(string $type): float
    {
        $transactions = $this->baseJoinedQuery()
            ->where('transactions.type', $type)
            ->get();

        $monthlyTotals = [];
        foreach ($transactions as $transaction) {
            $converted = $this->convertCurrency((float) $transaction->amount, $transaction->currency);
            $monthKey = Carbon::parse($transaction->datetime)->format('Y-m');
            $monthlyTotals[$monthKey] = ($monthlyTotals[$monthKey] ?? 0) + $converted;
        }

        if (empty($monthlyTotals)) {
            return 0.0;
        }

        return array_sum($monthlyTotals) / count($monthlyTotals);
    }

    private function getComparisons(): array
    {
        $daysInPeriod = $this->startDate->diffInDays($this->endDate);
        $previousPeriodStart = $this->startDate->copy()->subDays($daysInPeriod);
        $previousPeriodEnd = $this->startDate->copy()->subDay();

        $currentPeriodTotals = $this->getPeriodTotals();
        $previousPeriodTotals = $this->getPeriodTotalsForRange($previousPeriodStart, $previousPeriodEnd);

        $calculateChange = function ($current, $previous) {
            if ($previous == 0) {
                return $current > 0 ? 100.0 : 0.0;
            }

            return (($current - $previous) / abs($previous)) * 100;
        };

        return [
            'previous_period' => [
                'income_change_percent' => $calculateChange(
                    $currentPeriodTotals['income'],
                    $previousPeriodTotals['income']
                ),
                'expense_change_percent' => $calculateChange(
                    $currentPeriodTotals['expense'],
                    $previousPeriodTotals['expense']
                ),
                'savings_rate_change' => $calculateChange(
                    $currentPeriodTotals['savings_rate'],
                    $previousPeriodTotals['savings_rate']
                ),
            ],
        ];
    }

    private function getPeriodTotals(): array
    {
        return $this->getPeriodTotalsForRange($this->startDate, $this->endDate);
    }

    private function getPeriodTotalsForRange(Carbon $startDate, Carbon $endDate): array
    {
        $query = Transaction::join('wallets', 'transactions.wallet_id', '=', 'wallets.id')
            ->where('transactions.user_id', $this->user->id)
            ->nonTransfer()
            ->whereBetween('transactions.datetime', [$startDate, $endDate])
            ->select('transactions.amount', 'transactions.type', 'wallets.currency');

        if (! empty($this->walletIds)) {
            $query->whereIn('transactions.wallet_id', $this->walletIds);
        }

        $transactions = $query->get();

        $income = 0.0;
        $expense = 0.0;

        foreach ($transactions as $transaction) {
            $converted = $this->convertCurrency((float) $transaction->amount, $transaction->currency);

            if ($transaction->type === 'income') {
                $income += $converted;
            } else {
                $expense += $converted;
            }
        }

        $savingsRate = $income > 0 ? (($income - $expense) / $income) * 100 : 0;

        return [
            'income' => $income,
            'expense' => $expense,
            'savings_rate' => $savingsRate,
        ];
    }

    private function getTopCategories(): array
    {
        $transactions = $this->baseEagerQuery(['categories', 'wallet'])->get();

        $incomeTransactions = $transactions->where('type', 'income');
        $expenseTransactions = $transactions->where('type', 'expense');

        return [
            'income' => $this->groupTransactionsByCategory($incomeTransactions, 5),
            'expenses' => $this->groupTransactionsByCategory($expenseTransactions, 5),
        ];
    }

    /**
     * @SuppressWarnings(PHPMD.IfStatementAssignment)
     */
    private function getLargestTransactions(): array
    {
        $transactions = $this->baseEagerQuery(['categories', 'wallet'])->get();

        $findLargest = function ($type) use ($transactions) {
            $filtered = $transactions->where('type', $type);
            if ($filtered->isEmpty()) {
                return null;
            }

            $largest = null;
            $largestAmount = 0;

            foreach ($filtered as $transaction) {
                $converted = $this->convertCurrency(
                    (float) $transaction->amount,
                    $transaction->wallet->currency ?? 'USD'
                );

                if ($converted > $largestAmount) {
                    $largestAmount = $converted;
                    $largest = [
                        'amount' => $converted,
                        'description' => $transaction->description,
                        'date' => $transaction->datetime ? Carbon::parse($transaction->datetime)->toDateString() : null,
                        'category' => $transaction->categories->first()?->name,
                    ];
                }
            }

            return $largest;
        };

        return [
            'income' => $findLargest('income'),
            'expense' => $findLargest('expense'),
        ];
    }

    private function getSpendingTrends(string $period = 'month'): array
    {
        $currentPeriodData = $this->getTrendsForPeriod($this->startDate, $this->endDate, $period);

        $daysInPeriod = $this->startDate->diffInDays($this->endDate);
        $previousStartDate = $this->startDate->copy()->subDays($daysInPeriod + 1);
        $previousEndDate = $this->startDate->copy()->subDay();

        $previousPeriodData = $this->getTrendsForPeriod($previousStartDate, $previousEndDate, $period);

        return [
            'current_period' => $currentPeriodData,
            'previous_period' => $previousPeriodData,
        ];
    }

    private function getTrendsForPeriod(Carbon $startDate, Carbon $endDate, string $period): array
    {
        $query = Transaction::join('wallets', 'transactions.wallet_id', '=', 'wallets.id')
            ->where('transactions.user_id', $this->user->id)
            ->nonTransfer()
            ->whereBetween('transactions.datetime', [$startDate, $endDate])
            ->where('transactions.type', 'expense')
            ->select('transactions.amount', 'transactions.datetime', 'wallets.currency');

        if (! empty($this->walletIds)) {
            $query->whereIn('transactions.wallet_id', $this->walletIds);
        }

        $transactions = $query->get();

        $groupedData = [];
        foreach ($transactions as $transaction) {
            $converted = $this->convertCurrency((float) $transaction->amount, $transaction->currency);

            $key = $period === 'month'
                ? Carbon::parse($transaction->datetime)->format('Y-m-01')
                : Carbon::parse($transaction->datetime)->format('Y-m-d');

            $groupedData[$key] = ($groupedData[$key] ?? 0) + $converted;
        }

        ksort($groupedData);

        return collect($groupedData)->map(function ($amount, $date) {
            return [
                'date' => $date,
                'amount' => (float) $amount,
            ];
        })->values()->toArray();
    }

    private function getCategoryDistribution(): array
    {
        $transactions = $this->baseEagerQuery(['categories', 'wallet'])
            ->where('type', 'expense')
            ->get();

        $distribution = [
            'essential' => 0,
            'non_essential' => 0,
            'savings' => 0,
            'investments' => 0,
        ];

        $total = 0;

        foreach ($transactions as $transaction) {
            $converted = $this->convertCurrency(
                (float) $transaction->amount,
                $transaction->wallet->currency ?? 'USD'
            );

            $total += $converted;

            $category = $transaction->categories->first();
            $classification = $this->classifyCategory($category);
            $distribution[$classification] += $converted;
        }

        if ($total > 0) {
            foreach ($distribution as $key => $amount) {
                $distribution[$key] = ($amount / $total) * 100;
            }
        }

        return $distribution;
    }

    private function classifyCategory($category): string
    {
        if (! $category) {
            return 'non_essential';
        }

        $name = strtolower($category->name ?? '');
        $slug = strtolower($category->slug ?? '');
        $text = $name . ' ' . $slug;

        // @phpcs:ignore
        if (preg_match('/\b(rent|mortgage|hous|utilit|electric|water|gas|grocer|food|meal|health|medic|pharma|doctor|hospital|insur|transport|fuel|petrol|diesel|commut|bus|train|taxi)\b/i', $text)) {
            return 'essential';
        }

        if (preg_match('/\b(saving|emergency|reserve|rainy.?day)\b/i', $text)) {
            return 'savings';
        }

        if (preg_match('/\b(invest|stock|bond|mutual|etf|crypto|retire|401k|ira|pension|dividend)\b/i', $text)) {
            return 'investments';
        }

        return 'non_essential';
    }

    private function getPeriodSummary(): array
    {
        $today = Carbon::now();
        $daysRemaining = $today->diffInDays($this->endDate, false);
        $daysElapsed = $this->startDate->diffInDays($today);
        $totalDays = $this->startDate->diffInDays($this->endDate);

        $totals = $this->getPeriodTotalsForRange($this->startDate, $today);

        $currentIncome = $totals['income'];
        $currentExpenses = $totals['expense'];

        $projectedIncome = $daysElapsed > 0
            ? ($currentIncome / $daysElapsed) * $totalDays
            : 0;

        $projectedExpenses = $daysElapsed > 0
            ? ($currentExpenses / $daysElapsed) * $totalDays
            : 0;

        return [
            'start_date' => $this->startDate->toDateString(),
            'end_date' => $this->endDate->toDateString(),
            'days_remaining' => max(0, $daysRemaining),
            'projected_income' => round($projectedIncome, 2),
            'projected_expenses' => round($projectedExpenses, 2),
        ];
    }

    private function getPartyData(string $type = 'expense'): array
    {
        $transactions = $this->baseEagerQuery(['party', 'wallet'])
            ->where('type', $type)
            ->get();

        $partyData = [];
        foreach ($transactions as $transaction) {
            $converted = $this->convertCurrency(
                (float) $transaction->amount,
                $transaction->wallet->currency ?? 'USD'
            );

            $partyId = $transaction->party_id ?? 'uncategorized';

            if (! isset($partyData[$partyId])) {
                $partyData[$partyId] = [
                    'id' => $partyId,
                    'name' => $transaction->party ? $transaction->party->name : 'Uncategorized',
                    'amount' => 0,
                    'transaction_count' => 0,
                    'percentage' => 0,
                ];
            }

            $partyData[$partyId]['amount'] += $converted;
            $partyData[$partyId]['transaction_count']++;
        }

        $totalAmount = array_sum(array_column($partyData, 'amount'));

        return collect($partyData)
            ->map(function ($item) use ($totalAmount) {
                $item['percentage'] = $totalAmount > 0 ? ($item['amount'] / $totalAmount) * 100 : 0;

                return $item;
            })
            ->sortByDesc('amount')
            ->values()
            ->toArray();
    }

    private function getCategorySpendingData(string $type = 'expense'): array
    {
        $transactions = $this->baseEagerQuery(['categories', 'wallet'])
            ->where('type', $type)
            ->get();

        return $this->groupTransactionsByCategory($transactions);
    }

    private function groupTransactionsByCategory($transactions, ?int $limit = null): array
    {
        $categoryData = [];

        foreach ($transactions as $transaction) {
            $converted = $this->convertCurrency(
                (float) $transaction->amount,
                $transaction->wallet->currency ?? 'USD'
            );

            $category = $transaction->categories->first();

            if (! $category) {
                $categoryId = 'uncategorized';
                $categoryName = 'Uncategorized';
            } else {
                $categoryId = $category->id;
                $categoryName = $category->name;
            }

            if (! isset($categoryData[$categoryId])) {
                $categoryData[$categoryId] = [
                    'id' => $categoryId,
                    'name' => $categoryName,
                    'amount' => 0,
                    'transaction_count' => 0,
                ];
            }

            $categoryData[$categoryId]['amount'] += $converted;
            $categoryData[$categoryId]['transaction_count']++;
        }

        $totalAmount = array_sum(array_column($categoryData, 'amount'));

        return collect($categoryData)
            ->sortByDesc('amount')
            ->when($limit, fn ($collection) => $collection->take($limit))
            ->map(function ($item) use ($totalAmount) {
                $item['amount'] = (float) $item['amount'];
                $item['percentage'] = $totalAmount > 0 ? ($item['amount'] / $totalAmount) * 100 : 0;

                return $item;
            })
            ->values()
            ->toArray();
    }

    private function getMonthlyCashFlowData(): array
    {
        $transactions = $this->baseJoinedQuery()->get();

        $monthlyData = [];
        foreach ($transactions as $transaction) {
            $converted = $this->convertCurrency((float) $transaction->amount, $transaction->currency);

            $period = Carbon::parse($transaction->datetime)->format('Y-m');

            if (! isset($monthlyData[$period])) {
                $monthlyData[$period] = ['income' => 0, 'expense' => 0];
            }

            if ($transaction->type === 'income') {
                $monthlyData[$period]['income'] += $converted;
            } else {
                $monthlyData[$period]['expense'] += $converted;
            }
        }

        ksort($monthlyData);

        return collect($monthlyData)->map(function ($data, $period) {
            return [
                'period' => $period,
                'income' => (float) $data['income'],
                'expense' => (float) $data['expense'],
                'net' => (float) ($data['income'] - $data['expense']),
            ];
        })->values()->toArray();
    }

    private function getExpenseByWalletData(): array
    {
        $transactions = $this->baseEagerQuery(['wallet'])
            ->where('type', 'expense')
            ->get();

        $walletData = [];
        foreach ($transactions as $transaction) {
            $converted = $this->convertCurrency(
                (float) $transaction->amount,
                $transaction->wallet->currency ?? 'USD'
            );

            $walletId = $transaction->wallet_id;

            if (! isset($walletData[$walletId])) {
                $walletData[$walletId] = [
                    'wallet_id' => $walletId,
                    'wallet_name' => $transaction->wallet->name ?? 'Unknown',
                    'amount' => 0,
                    'transaction_count' => 0,
                ];
            }

            $walletData[$walletId]['amount'] += $converted;
            $walletData[$walletId]['transaction_count']++;
        }

        $totalAmount = array_sum(array_column($walletData, 'amount'));

        return collect($walletData)
            ->map(function ($item) use ($totalAmount) {
                $item['percentage'] = $totalAmount > 0 ? ($item['amount'] / $totalAmount) * 100 : 0;

                return $item;
            })
            ->sortByDesc('amount')
            ->values()
            ->toArray();
    }

    private function getActivityMetrics(): array
    {
        $baseQuery = $this->baseQualifiedQuery();

        $stats = (clone $baseQuery)->selectRaw('
            count(*) as total_count,
            count(distinct party_id) as unique_parties,
            sum(case when type = "income" then amount else 0 end) as total_income,
            count(case when type = "income" then 1 end) as income_count,
            sum(case when type = "expense" then amount else 0 end) as total_expenses,
            count(case when type = "expense" then 1 end) as expense_count
        ')->first();

        $transactionCount = (int) $stats->total_count;
        $periodDays = max(1, $this->startDate->diffInDays($this->endDate));

        $avgIncome = $stats->income_count > 0 ? round($stats->total_income / $stats->income_count, 2) : 0;
        $avgExpense = $stats->expense_count > 0 ? round($stats->total_expenses / $stats->expense_count, 2) : 0;

        return [
            'transaction_count' => $transactionCount,
            'unique_parties' => (int) $stats->unique_parties,
            'average_income_transaction' => $avgIncome,
            'average_expense_transaction' => $avgExpense,
            'frequency' => [
                'per_day' => round($transactionCount / $periodDays, 2),
                'per_week' => round($transactionCount / max(1, $periodDays / 7), 2),
                'per_month' => round($transactionCount / max(1, $periodDays / 30), 2),
            ],
            'busiest_day' => $this->getBusiestDay($baseQuery),
            'most_frequent_party' => $this->getMostFrequentParty($baseQuery),
            'most_used_category' => $this->getMostUsedCategory($baseQuery),
        ];
    }

    private function getBusiestDay($baseQuery): ?string
    {
        $row = (clone $baseQuery)
            ->selectRaw('DAYNAME(datetime) as day_name, count(*) as cnt')
            ->groupByRaw('DAYNAME(datetime)')
            ->orderByDesc('cnt')
            ->first();

        return $row?->day_name;
    }

    private function getMostFrequentParty($baseQuery): ?array
    {
        $row = (clone $baseQuery)
            ->join('parties', 'transactions.party_id', '=', 'parties.id')
            ->selectRaw('parties.id, parties.name, count(*) as transaction_count')
            ->groupBy('parties.id', 'parties.name')
            ->orderByDesc('transaction_count')
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'id' => $row->id,
            'name' => $row->name,
            'transaction_count' => (int) $row->transaction_count,
        ];
    }

    private function getMostUsedCategory($baseQuery): ?array
    {
        $row = \DB::table('categorizables')
            ->join('categories', 'categorizables.category_id', '=', 'categories.id')
            ->where('categorizables.categorizable_type', Transaction::class)
            ->whereIn('categorizables.categorizable_id', (clone $baseQuery)->select('transactions.id'))
            ->selectRaw('categories.id, categories.name, count(*) as transaction_count')
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('transaction_count')
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'id' => $row->id,
            'name' => $row->name,
            'transaction_count' => (int) $row->transaction_count,
        ];
    }
}
