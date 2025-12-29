<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Models\Transaction;
use App\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Statistics', description: 'Financial statistics and analytics')]
class StatsController extends ApiController
{
    #[OA\Get(
        path: '/stats',
        summary: 'Get financial statistics',
        tags: ['Statistics'],
        parameters: [
            new OA\Parameter(
                name: 'start_date',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date')
            ),
            new OA\Parameter(
                name: 'end_date',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date')
            ),
            new OA\Parameter(
                name: 'wallet_ids',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', description: 'Comma-separated wallet IDs')
            ),
            new OA\Parameter(
                name: 'period',
                in: 'query',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['day', 'week', 'month', 'year'],
                    default: 'month'
                )
            ),
            new OA\Parameter(
                name: 'preset',
                in: 'query',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['all_time', 'current_week', 'current_month', 'last_3_months'],
                    description: 'Preset date range (overrides start_date/end_date)'
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statistics retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object', properties: [
                            new OA\Property(property: 'overview', type: 'object', properties: [
                                new OA\Property(property: 'total_balance', type: 'number', format: 'float'),
                                new OA\Property(property: 'net_worth', type: 'number', format: 'float'),
                                new OA\Property(property: 'total_income', type: 'number', format: 'float'),
                                new OA\Property(property: 'total_expenses', type: 'number', format: 'float'),
                                new OA\Property(property: 'net_cash_flow', type: 'number', format: 'float'),
                                new OA\Property(property: 'avg_monthly_income', type: 'number', format: 'float'),
                                new OA\Property(property: 'avg_monthly_expenses', type: 'number', format: 'float'),
                                new OA\Property(property: 'savings_rate', type: 'number', format: 'float'),
                            ]),
                        ]),
                    ]
                )
            ),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Set default date range
        $endDate = Carbon::now()->endOfDay();
        $startDate = Carbon::now()->subDays(30)->startOfDay();

        // Handle preset periods (overrides start_date/end_date)
        if ($request->has('preset')) {
            $preset = $request->input('preset');
            $endDate = Carbon::now()->endOfDay();

            switch ($preset) {
                case 'all_time':
                    $startDate = Carbon::parse('2000-01-01')->startOfDay();
                    break;
                case 'current_week':
                    $startDate = Carbon::now()->startOfWeek()->startOfDay();
                    break;
                case 'current_month':
                    $startDate = Carbon::now()->startOfMonth()->startOfDay();
                    break;
                case 'last_3_months':
                    $startDate = Carbon::now()->subMonths(3)->startOfDay();
                    break;
                default:
                    $startDate = Carbon::now()->subDays(30)->startOfDay();
            }
        } else {
            // Apply date filters if provided
            if ($request->has('start_date')) {
                $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
            }

            if ($request->has('end_date')) {
                $endDate = Carbon::parse($request->input('end_date'))->endOfDay();
            }
        }

        // Get wallet IDs filter
        $walletIds = [];
        if ($request->has('wallet_ids')) {
            $walletIds = explode(',', $request->input('wallet_ids'));
            $walletIds = array_filter(array_map('intval', $walletIds));

            // Validate that all provided wallet IDs exist and belong to the user
            $validWalletIds = Wallet::where('user_id', $user->id)
                ->whereIn('id', $walletIds)
                ->pluck('id')
                ->toArray();

            // Check if all provided wallet IDs are valid
            $invalidWalletIds = array_diff($walletIds, $validWalletIds);
            if (! empty($invalidWalletIds)) {
                return response()->json([
                    'message' => 'One or more wallet IDs are invalid or do not belong to the user.',
                    'invalid_wallet_ids' => array_values($invalidWalletIds),
                ], 422);
            }
        }

        // Generate cache key based on user, dates, and filters
        $cacheKey = $this->generateCacheKey($user->id, $startDate, $endDate, $walletIds, $request->input('period', 'month'));

        $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($user, $startDate, $endDate, $walletIds, $request) {
            // Base query for transactions
            $transactionQuery = Transaction::where('user_id', $user->id)
                ->whereBetween('datetime', [$startDate, $endDate]);

            if (! empty($walletIds)) {
                $transactionQuery->whereIn('wallet_id', $walletIds);
            }

            // Calculate overview stats
            $overview = [
                'total_balance' => $this->getTotalBalance($user, $walletIds),
                'net_worth' => $this->getNetWorth($user, $walletIds),
                'total_income' => 0,
                'total_expenses' => 0,
                'net_cash_flow' => 0,
                'avg_monthly_income' => $this->getAverageMonthlyAmount($user, 'income', $walletIds),
                'avg_monthly_expenses' => $this->getAverageMonthlyAmount($user, 'expense', $walletIds),
                'savings_rate' => 0,
            ];

            // Calculate period totals
            $periodTotals = (clone $transactionQuery)
                ->selectRaw("SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as total_income")
                ->selectRaw("SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expenses")
                ->first();

            $overview['total_income'] = (float) ($periodTotals->total_income ?? 0);
            $overview['total_expenses'] = (float) ($periodTotals->total_expenses ?? 0);
            $overview['net_cash_flow'] = $overview['total_income'] - $overview['total_expenses'];

            // Calculate savings rate (avoid division by zero)
            if ($overview['total_income'] > 0) {
                $overview['savings_rate'] =
                    (($overview['total_income'] - $overview['total_expenses']) / $overview['total_income']) * 100;
            }

            return [
                'overview' => $overview,
                'comparisons' => $this->getComparisons($user, $startDate, $endDate, $walletIds),
                'top_categories' => $this->getTopCategories($user, $startDate, $endDate, $walletIds),
                'largest_transactions' => $this->getLargestTransactions($user, $startDate, $endDate, $walletIds),
                'spending_trends' => $this->getSpendingTrends($user, $startDate, $endDate, $request->input('period', 'month')),
                'category_distribution' => $this->getCategoryDistribution($user, $startDate, $endDate, $walletIds),
                'period_summary' => $this->getPeriodSummary($user, $startDate, $endDate, $walletIds),
                'charts' => [
                    'party_spending' => $this->getPartyData($user, $startDate, $endDate, $walletIds, 'expense'),
                    'party_income' => $this->getPartyData($user, $startDate, $endDate, $walletIds, 'income'),
                    'category_spending' => $this->getCategorySpendingData($user, $startDate, $endDate, $walletIds, 'expense'),
                    'income_sources' => $this->getCategorySpendingData($user, $startDate, $endDate, $walletIds, 'income'),
                    'monthly_cash_flow' => $this->getMonthlyCashFlowData($user, $startDate, $endDate, $walletIds),
                    'expense_by_wallet' => $this->getExpenseByWalletData($user, $startDate, $endDate, $walletIds),
                ],
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Generate a deterministic cache key for stats based on request parameters.
     *
     * @param  int  $userId  The authenticated user's ID
     * @param  Carbon  $startDate  Start of the date range
     * @param  Carbon  $endDate  End of the date range
     * @param  array  $walletIds  Filter by specific wallet IDs (empty for all)
     * @param  string  $period  Grouping period (day, week, month, year)
     * @return string The generated cache key
     */
    private function generateCacheKey(int $userId, Carbon $startDate, Carbon $endDate, array $walletIds, string $period): string
    {
        $version = Cache::get('stats:user:'.$userId.':version', 1);

        $params = [
            'start' => $startDate->toDateString(),
            'end' => $endDate->toDateString(),
            'wallets' => implode(',', $walletIds),
            'period' => $period,
            'v' => $version,
        ];

        return 'stats:user:'.$userId.':'.md5(json_encode($params));
    }

    /**
     * Invalidate all stats cache for a user.
     *
     * For Redis, deletes all matching keys. For file/database cache,
     * increments a version key to invalidate existing cache entries.
     *
     * @param  int  $userId  The user ID whose cache should be invalidated
     */
    public static function invalidateUserCache(int $userId): void
    {
        $pattern = 'stats:user:'.$userId.':*';

        if (config('cache.default') === 'redis') {
            $redis = Cache::getRedis();
            $prefix = config('cache.prefix', 'laravel_cache');
            $keys = $redis->keys("{$prefix}:{$pattern}");
            foreach ($keys as $key) {
                $redis->del($key);
            }
        } else {
            // For file/database cache, we can't easily pattern-match
            // Instead, we'll use a version key approach
            Cache::increment('stats:user:'.$userId.':version');
        }
    }

    /**
     * Calculate total balance across user's wallets.
     *
     * @param  mixed  $user  The authenticated user
     * @param  array  $walletIds  Filter by specific wallet IDs (empty for all)
     * @return float Total balance
     */
    private function getTotalBalance($user, array $walletIds = []): float
    {
        $query = Wallet::where('user_id', $user->id);

        if (! empty($walletIds)) {
            $query->whereIn('id', $walletIds);
        }

        return (float) $query->sum('balance');
    }

    /**
     * Calculate user's net worth.
     *
     * Currently returns total balance. Can be extended to include
     * other assets and liabilities in the future.
     *
     * @param  mixed  $user  The authenticated user
     * @param  array  $walletIds  Filter by specific wallet IDs (empty for all)
     * @return float Net worth value
     */
    private function getNetWorth($user, array $walletIds = []): float
    {
        return $this->getTotalBalance($user, $walletIds);
    }

    /**
     * Calculate average monthly amount for a transaction type.
     *
     * Groups transactions by month and calculates the average of monthly totals.
     *
     * @param  mixed  $user  The authenticated user
     * @param  string  $type  Transaction type: 'income' or 'expense'
     * @param  array  $walletIds  Filter by specific wallet IDs (empty for all)
     * @return float Average monthly amount
     */
    private function getAverageMonthlyAmount($user, string $type, array $walletIds = []): float
    {
        $subQuery = Transaction::where('user_id', $user->id)
            ->where('type', $type)
            ->select(
                DB::raw('YEAR(datetime) as year'),
                DB::raw('MONTH(datetime) as month'),
                DB::raw('SUM(amount) as monthly_total')
            )
            ->groupBy(DB::raw('YEAR(datetime)'), DB::raw('MONTH(datetime)'));

        if (! empty($walletIds)) {
            $subQuery->whereIn('wallet_id', $walletIds);
        }

        $result = DB::table(DB::raw("({$subQuery->toSql()}) as monthly_totals"))
            ->mergeBindings($subQuery->getQuery())
            ->select(DB::raw('AVG(monthly_total) as avg_monthly'))
            ->first();

        return (float) ($result->avg_monthly ?? 0);
    }

    /**
     * Get period-over-period comparisons for income, expenses, and savings rate.
     *
     * Compares current period with the equivalent previous period.
     *
     * @param  mixed  $user  The authenticated user
     * @param  Carbon  $startDate  Start of current period
     * @param  Carbon  $endDate  End of current period
     * @param  array  $walletIds  Filter by specific wallet IDs
     * @return array Comparison percentages for income, expenses, and savings rate
     */
    private function getComparisons($user, $startDate, $endDate, array $walletIds = []): array
    {
        $daysInPeriod = $startDate->diffInDays($endDate);
        $previousPeriodStart = $startDate->copy()->subDays($daysInPeriod);
        $previousPeriodEnd = $startDate->copy()->subDay();

        $currentPeriodTotals = $this->getPeriodTotals($user, $startDate, $endDate, $walletIds);
        $previousPeriodTotals = $this->getPeriodTotals($user, $previousPeriodStart, $previousPeriodEnd, $walletIds);

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
            // TODO: Implement year_over_year comparison
        ];
    }

    /**
     * Calculate income, expense, and savings rate totals for a period.
     *
     * @param  mixed  $user  The authenticated user
     * @param  Carbon  $startDate  Start of the period
     * @param  Carbon  $endDate  End of the period
     * @param  array  $walletIds  Filter by specific wallet IDs
     * @return array Totals with income, expense, and savings_rate keys
     */
    private function getPeriodTotals($user, $startDate, $endDate, array $walletIds = []): array
    {
        $query = Transaction::where('user_id', $user->id)
            ->whereBetween('datetime', [$startDate, $endDate]);

        if (! empty($walletIds)) {
            $query->whereIn('wallet_id', $walletIds);
        }

        $result = $query
            ->selectRaw("SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as income")
            ->selectRaw("SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expense")
            ->first();

        $income = (float) ($result->income ?? 0);
        $expense = (float) ($result->expense ?? 0);
        $savingsRate = $income > 0 ? (($income - $expense) / $income) * 100 : 0;

        return [
            'income' => $income,
            'expense' => $expense,
            'savings_rate' => $savingsRate,
        ];
    }

    /**
     * Get top categories by amount for both income and expenses.
     *
     * @param  mixed  $user  The authenticated user
     * @param  Carbon  $startDate  Start of the date range
     * @param  Carbon  $endDate  End of the date range
     * @param  array  $walletIds  Filter by specific wallet IDs
     * @return array Top 5 categories for income and expenses with percentages
     */
    private function getTopCategories($user, $startDate, $endDate, array $walletIds = []): array
    {
        $query = Transaction::with('categories')
            ->where('user_id', $user->id)
            ->whereBetween('datetime', [$startDate, $endDate]);

        if (! empty($walletIds)) {
            $query->whereIn('wallet_id', $walletIds);
        }

        $transactions = $query->get();

        $incomeTransactions = $transactions->where('type', 'income');
        $expenseTransactions = $transactions->where('type', 'expense');

        return [
            'income' => $this->groupTransactionsByCategory($incomeTransactions, 5),
            'expenses' => $this->groupTransactionsByCategory($expenseTransactions, 5),
        ];
    }

    /**
     * Get the largest income and expense transactions in a period.
     *
     * @param  mixed  $user  The authenticated user
     * @param  Carbon  $startDate  Start of the date range
     * @param  Carbon  $endDate  End of the date range
     * @param  array  $walletIds  Filter by specific wallet IDs
     * @return array Largest income and expense transactions with details
     */
    private function getLargestTransactions($user, $startDate, $endDate, array $walletIds = []): array
    {
        $query = Transaction::where('user_id', $user->id)
            ->whereBetween('datetime', [$startDate, $endDate]);

        if (! empty($walletIds)) {
            $query->whereIn('wallet_id', $walletIds);
        }

        $largestIncome = (clone $query)
            ->where('type', 'income')
            ->orderBy('amount', 'desc')
            ->first();

        $largestExpense = (clone $query)
            ->where('type', 'expense')
            ->orderBy('amount', 'desc')
            ->first();

        $formatTransaction = function ($transaction) {
            if (! $transaction) {
                return null;
            }

            return [
                'amount' => (float) $transaction->amount,
                'description' => $transaction->description,
                'date' => $transaction->datetime ? Carbon::parse($transaction->datetime)->toDateString() : null,
                'category' => $transaction->categories->first()?->name,
            ];
        };

        return [
            'income' => $formatTransaction($largestIncome),
            'expense' => $formatTransaction($largestExpense),
        ];
    }

    /**
     * Get spending trends comparing current and previous periods.
     *
     * @param  mixed  $user  The authenticated user
     * @param  Carbon  $startDate  Start of current period
     * @param  Carbon  $endDate  End of current period
     * @param  string  $period  Grouping period: 'day', 'week', 'month', or 'year'
     * @return array Current and previous period spending data
     */
    private function getSpendingTrends($user, $startDate, $endDate, string $period = 'month'): array
    {
        $currentPeriodData = $this->getTrendsForPeriod($user, $startDate, $endDate, $period);

        // Calculate previous period dates
        $daysInPeriod = $startDate->diffInDays($endDate);
        $previousStartDate = $startDate->copy()->subDays($daysInPeriod + 1);
        $previousEndDate = $startDate->copy()->subDay();

        $previousPeriodData = $this->getTrendsForPeriod($user, $previousStartDate, $previousEndDate, $period);

        return [
            'current_period' => $currentPeriodData,
            'previous_period' => $previousPeriodData,
        ];
    }

    /**
     * Get expense trends data for a specific period.
     *
     * @param  mixed  $user  The authenticated user
     * @param  Carbon  $startDate  Start of the period
     * @param  Carbon  $endDate  End of the period
     * @param  string  $period  Grouping period: 'day' or 'month'
     * @return array Daily or monthly expense totals
     */
    private function getTrendsForPeriod($user, $startDate, $endDate, $period): array
    {
        $query = Transaction::where('user_id', $user->id)
            ->whereBetween('datetime', [$startDate, $endDate])
            ->where('type', 'expense')
            ->select(
                DB::raw('DATE(datetime) as date'),
                DB::raw('SUM(amount) as amount')
            )
            ->groupBy('date')
            ->orderBy('date');

        // If period is month, group by month
        if ($period === 'month') {
            $query->selectRaw('YEAR(datetime) as year, MONTH(datetime) as month')
                ->groupBy('year', 'month')
                ->orderBy('year')
                ->orderBy('month');
        }

        $transactions = $query->get();

        // Format the data for the response
        return $transactions->map(function ($item) use ($period) {
            $date = $period === 'month'
                ? "{$item->year}-".str_pad($item->month, 2, '0', STR_PAD_LEFT).'-01'
                : $item->date;

            return [
                'date' => $date,
                'amount' => (float) $item->amount,
            ];
        })->toArray();
    }

    /**
     * Get expense distribution by classification (essential, savings, investments, discretionary).
     *
     * Uses pattern matching on category names to classify expenses automatically.
     *
     * @param  mixed  $user  The authenticated user
     * @param  Carbon  $startDate  Start of the date range
     * @param  Carbon  $endDate  End of the date range
     * @param  array  $walletIds  Filter by specific wallet IDs
     * @return array Distribution percentages by classification
     */
    private function getCategoryDistribution($user, $startDate, $endDate, array $walletIds = []): array
    {
        $query = Transaction::with('categories')
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween('datetime', [$startDate, $endDate]);

        if (! empty($walletIds)) {
            $query->whereIn('wallet_id', $walletIds);
        }

        $transactions = $query->get();

        $distribution = [
            'essential' => 0,
            'non_essential' => 0,
            'savings' => 0,
            'investments' => 0,
        ];

        $total = $transactions->sum('amount');

        if ($total > 0) {
            foreach ($transactions as $transaction) {
                $category = $transaction->categories->first();
                $classification = $this->classifyCategory($category);
                $distribution[$classification] += $transaction->amount;
            }

            foreach ($distribution as $key => $amount) {
                $distribution[$key] = ($amount / $total) * 100;
            }
        }

        return $distribution;
    }

    /**
     * Classify a category based on its name using pattern matching.
     *
     * @param  mixed  $category  The category model or null
     * @return string Classification: essential, savings, investments, or non_essential
     */
    private function classifyCategory($category): string
    {
        if (! $category) {
            return 'non_essential';
        }

        $name = strtolower($category->name ?? '');
        $slug = strtolower($category->slug ?? '');
        $text = $name.' '.$slug;

        // Essential: housing, utilities, food, healthcare, transport, insurance
        if (preg_match('/\b(rent|mortgage|hous|utilit|electric|water|gas|grocer|food|meal|health|medic|pharma|doctor|hospital|insur|transport|fuel|petrol|diesel|commut|bus|train|taxi)\b/i', $text)) {
            return 'essential';
        }

        // Savings
        if (preg_match('/\b(saving|emergency|reserve|rainy.?day)\b/i', $text)) {
            return 'savings';
        }

        // Investments
        if (preg_match('/\b(invest|stock|bond|mutual|etf|crypto|retire|401k|ira|pension|dividend)\b/i', $text)) {
            return 'investments';
        }

        return 'non_essential';
    }

    /**
     * Get summary and projections for the current period.
     *
     * Calculates days remaining and projects income/expenses based on
     * current daily averages.
     *
     * @param  mixed  $user  The authenticated user
     * @param  Carbon  $startDate  Start of the period
     * @param  Carbon  $endDate  End of the period
     * @param  array  $walletIds  Filter by specific wallet IDs
     * @return array Period summary with dates, days remaining, and projections
     */
    private function getPeriodSummary($user, $startDate, $endDate, array $walletIds = []): array
    {
        $today = Carbon::now();
        $daysRemaining = $today->diffInDays($endDate, false);
        $daysElapsed = $startDate->diffInDays($today);
        $totalDays = $startDate->diffInDays($endDate);

        // Get actuals for the period so far
        $query = Transaction::where('user_id', $user->id)
            ->whereBetween('datetime', [$startDate, $today]);

        if (! empty($walletIds)) {
            $query->whereIn('wallet_id', $walletIds);
        }

        $totals = $query
            ->selectRaw("SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as income")
            ->selectRaw("SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expense")
            ->first();

        $currentIncome = (float) ($totals->income ?? 0);
        $currentExpenses = (float) ($totals->expense ?? 0);

        // Calculate projections based on current daily averages
        $projectedIncome = $daysElapsed > 0
            ? ($currentIncome / $daysElapsed) * $totalDays
            : 0;

        $projectedExpenses = $daysElapsed > 0
            ? ($currentExpenses / $daysElapsed) * $totalDays
            : 0;

        return [
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'days_remaining' => max(0, $daysRemaining),
            'projected_income' => round($projectedIncome, 2),
            'projected_expenses' => round($projectedExpenses, 2),
        ];
    }

    /**
     * Get transactions grouped by party/merchant for charts.
     *
     * @param  mixed  $user  The authenticated user
     * @param  Carbon  $startDate  Start of the date range
     * @param  Carbon  $endDate  End of the date range
     * @param  array  $walletIds  Filter by specific wallet IDs
     * @param  string  $type  Transaction type: 'expense' or 'income'
     * @return array Parties with amounts, percentages, and transaction counts
     */
    private function getPartyData($user, $startDate, $endDate, array $walletIds = [], string $type = 'expense'): array
    {
        $query = Transaction::with('party')
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->whereBetween('datetime', [$startDate, $endDate]);

        if (! empty($walletIds)) {
            $query->whereIn('wallet_id', $walletIds);
        }

        $transactions = $query->get();

        // Group by party
        $partyData = $transactions->groupBy('party_id')->map(function ($transactions, $partyId) {
            $party = $transactions->first()->party;
            $total = $transactions->sum('amount');

            return [
                'id' => $partyId,
                'name' => $party ? $party->name : 'Uncategorized',
                'amount' => (float) $total,
                'transaction_count' => $transactions->count(),
                'percentage' => 0, // Will be calculated after we have total
            ];
        })->values();

        // Calculate total and percentages
        $totalAmount = $partyData->sum('amount');

        return $partyData->map(function ($item) use ($totalAmount) {
            $item['percentage'] = $totalAmount > 0 ? ($item['amount'] / $totalAmount) * 100 : 0;

            return $item;
        })->sortByDesc('amount')->values()->toArray();
    }

    /**
     * Get category spending/income data for charts.
     *
     * @param  mixed  $user  The authenticated user
     * @param  Carbon  $startDate  Start of the date range
     * @param  Carbon  $endDate  End of the date range
     * @param  array  $walletIds  Filter by specific wallet IDs
     * @param  string  $type  Transaction type: 'expense' or 'income'
     * @return array Categories with amounts, percentages, and transaction counts
     */
    private function getCategorySpendingData($user, $startDate, $endDate, array $walletIds, string $type = 'expense'): array
    {
        $query = Transaction::with('categories')
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->whereBetween('datetime', [$startDate, $endDate]);

        if (! empty($walletIds)) {
            $query->whereIn('wallet_id', $walletIds);
        }

        return $this->groupTransactionsByCategory($query->get());
    }

    /**
     * Group transactions by category and calculate totals with percentages.
     *
     * @param  \Illuminate\Support\Collection  $transactions  Collection of transactions with categories loaded
     * @param  int|null  $limit  Maximum number of categories to return (null for all)
     * @return array Grouped category data with amounts, percentages, and counts
     */
    private function groupTransactionsByCategory($transactions, ?int $limit = null): array
    {
        $categoryData = [];

        foreach ($transactions as $transaction) {
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

            $categoryData[$categoryId]['amount'] += $transaction->amount;
            $categoryData[$categoryId]['transaction_count']++;
        }

        $totalAmount = array_sum(array_column($categoryData, 'amount'));

        $result = collect($categoryData)
            ->sortByDesc('amount')
            ->when($limit, fn ($collection) => $collection->take($limit))
            ->map(function ($item) use ($totalAmount) {
                $item['amount'] = (float) $item['amount'];
                $item['percentage'] = $totalAmount > 0 ? ($item['amount'] / $totalAmount) * 100 : 0;

                return $item;
            })
            ->values()
            ->toArray();

        return $result;
    }

    /**
     * Get monthly cash flow data for line/bar charts.
     *
     * Groups transactions by month and calculates income, expense, and net for each.
     *
     * @param  mixed  $user  The authenticated user
     * @param  Carbon  $startDate  Start of the date range
     * @param  Carbon  $endDate  End of the date range
     * @param  array  $walletIds  Filter by specific wallet IDs
     * @return array Monthly periods with income, expense, and net amounts
     */
    private function getMonthlyCashFlowData($user, $startDate, $endDate, array $walletIds = []): array
    {
        $query = Transaction::where('user_id', $user->id)
            ->whereBetween('datetime', [$startDate, $endDate])
            ->selectRaw('YEAR(datetime) as year')
            ->selectRaw('MONTH(datetime) as month')
            ->selectRaw("SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as income")
            ->selectRaw("SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expense")
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month');

        if (! empty($walletIds)) {
            $query->whereIn('wallet_id', $walletIds);
        }

        $results = $query->get();

        return $results->map(function ($item) {
            return [
                'period' => "{$item->year}-".str_pad($item->month, 2, '0', STR_PAD_LEFT),
                'income' => (float) $item->income,
                'expense' => (float) $item->expense,
                'net' => (float) ($item->income - $item->expense),
            ];
        })->toArray();
    }

    /**
     * Get expense distribution by wallet for charts.
     *
     * Groups expenses by wallet and calculates amounts and percentages.
     *
     * @param  mixed  $user  The authenticated user
     * @param  Carbon  $startDate  Start of the date range
     * @param  Carbon  $endDate  End of the date range
     * @param  array  $walletIds  Filter by specific wallet IDs
     * @return array Wallets with expense amounts, percentages, and transaction counts
     */
    private function getExpenseByWalletData($user, $startDate, $endDate, array $walletIds = []): array
    {
        $query = Transaction::with('wallet')
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween('datetime', [$startDate, $endDate]);

        if (! empty($walletIds)) {
            $query->whereIn('wallet_id', $walletIds);
        } else {
            // If no wallet IDs provided, use all user's wallets
            $walletIds = $user->wallets()->pluck('id');
            $query->whereIn('wallet_id', $walletIds);
        }

        $results = $query->select(
            'wallet_id',
            DB::raw('SUM(amount) as total_amount'),
            DB::raw('COUNT(*) as transaction_count')
        )
            ->groupBy('wallet_id')
            ->get();

        $totalAmount = $results->sum('total_amount');

        return $results->map(function ($item) use ($totalAmount) {
            return [
                'wallet_id' => $item->wallet_id,
                'wallet_name' => $item->wallet->name ?? 'Unknown',
                'amount' => (float) $item->total_amount,
                'transaction_count' => $item->transaction_count,
                'percentage' => $totalAmount > 0 ? ($item->total_amount / $totalAmount) * 100 : 0,
            ];
        })->sortByDesc('amount')->values()->toArray();
    }
}
