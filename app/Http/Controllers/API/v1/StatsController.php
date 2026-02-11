<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\ExchangeRateService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;
use RuntimeException;

#[OA\Tag(name: 'Statistics', description: 'Financial statistics and analytics')]
class StatsController extends ApiController
{
    protected ExchangeRateService $exchangeRateService;

    public function __construct(ExchangeRateService $exchangeRateService)
    {
        $this->exchangeRateService = $exchangeRateService;
    }

    /**
     * Convert amount to target currency, throwing exception on failure.
     *
     * @throws \RuntimeException When currency conversion fails
     */
    private function convertCurrency(float $amount, string $fromCurrency, string $targetCurrency, $user): float
    {
        if ($fromCurrency === $targetCurrency) {
            return $amount;
        }

        $converted = $this->exchangeRateService->convert($amount, $fromCurrency, $targetCurrency, $user);

        if ($converted === null) {
            throw new RuntimeException(
                "Failed to convert {$fromCurrency} to {$targetCurrency}. Exchange rate unavailable."
            );
        }

        return $converted;
    }

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
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(
                                property: 'currency',
                                description: 'Currency used for all amounts',
                                type: 'string'
                            ),
                            new OA\Property(property: 'overview', properties: [
                                new OA\Property(property: 'total_balance', type: 'number', format: 'float'),
                                new OA\Property(property: 'net_worth', type: 'number', format: 'float'),
                                new OA\Property(property: 'total_income', type: 'number', format: 'float'),
                                new OA\Property(property: 'total_expenses', type: 'number', format: 'float'),
                                new OA\Property(property: 'net_cash_flow', type: 'number', format: 'float'),
                                new OA\Property(property: 'avg_monthly_income', type: 'number', format: 'float'),
                                new OA\Property(property: 'avg_monthly_expenses', type: 'number', format: 'float'),
                                new OA\Property(property: 'savings_rate', type: 'number', format: 'float'),
                            ], type: 'object'),
                        ], type: 'object'),
                    ]
                )
            ),
        ]
    )]
    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
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
                    'message' => __('One or more wallet IDs are invalid or do not belong to the user.'),
                    'invalid_wallet_ids' => array_values($invalidWalletIds),
                ], 422);
            }
        }

        $defaultCurrency = $user->getConfigValue('default-currency') ?? 'USD';

        // Generate cache key based on user, dates, and filters
        $cacheKey = $this->generateCacheKey(
            $user->id,
            $startDate,
            $endDate,
            $walletIds,
            $request->input('period', 'month')
        );

        $data = Cache::remember(
            $cacheKey,
            now()->addMinutes(5),
            function () use (
                $user,
                $startDate,
                $endDate,
                $walletIds,
                $request,
                $defaultCurrency
            ) {
                $periodTotals = $this->getPeriodTotals($user, $startDate, $endDate, $walletIds, $defaultCurrency);

                $overview = [
                    'total_balance' => $this->getTotalBalance($user, $walletIds, $defaultCurrency),
                    'net_worth' => $this->getNetWorth($user, $walletIds, $defaultCurrency),
                    'total_income' => $periodTotals['income'],
                    'total_expenses' => $periodTotals['expense'],
                    'net_cash_flow' => $periodTotals['income'] - $periodTotals['expense'],
                    'avg_monthly_income' => $this->getAverageMonthlyAmount(
                        $user,
                        'income',
                        $walletIds,
                        $startDate,
                        $endDate,
                        $defaultCurrency
                    ),
                    'avg_monthly_expenses' => $this->getAverageMonthlyAmount(
                        $user,
                        'expense',
                        $walletIds,
                        $startDate,
                        $endDate,
                        $defaultCurrency
                    ),
                    'savings_rate' => 0,
                ];

                if ($overview['total_income'] > 0) {
                    $overview['savings_rate'] =
                    (($overview['total_income'] - $overview['total_expenses']) / $overview['total_income']) * 100;
                }

                return [
                    'currency' => $defaultCurrency,
                    'overview' => $overview,
                    'comparisons' => $this->getComparisons($user, $startDate, $endDate, $walletIds, $defaultCurrency),
                    'top_categories' => $this->getTopCategories(
                        $user,
                        $startDate,
                        $endDate,
                        $walletIds,
                        $defaultCurrency
                    ),
                    'largest_transactions' => $this->getLargestTransactions(
                        $user,
                        $startDate,
                        $endDate,
                        $walletIds,
                        $defaultCurrency
                    ),
                    'spending_trends' => $this->getSpendingTrends(
                        $user,
                        $startDate,
                        $endDate,
                        $request->input('period', 'month'),
                        $walletIds,
                        $defaultCurrency
                    ),
                    'category_distribution' => $this->getCategoryDistribution(
                        $user,
                        $startDate,
                        $endDate,
                        $walletIds,
                        $defaultCurrency
                    ),
                    'period_summary' => $this->getPeriodSummary(
                        $user,
                        $startDate,
                        $endDate,
                        $walletIds,
                        $defaultCurrency
                    ),
                    'charts' => [
                        'party_spending' => $this->getPartyData(
                            $user,
                            $startDate,
                            $endDate,
                            $walletIds,
                            'expense',
                            $defaultCurrency
                        ),
                        'party_income' => $this->getPartyData(
                            $user,
                            $startDate,
                            $endDate,
                            $walletIds,
                            'income',
                            $defaultCurrency
                        ),
                        'category_spending' => $this->getCategorySpendingData(
                            $user,
                            $startDate,
                            $endDate,
                            $walletIds,
                            'expense',
                            $defaultCurrency
                        ),
                        'income_sources' => $this->getCategorySpendingData(
                            $user,
                            $startDate,
                            $endDate,
                            $walletIds,
                            'income',
                            $defaultCurrency
                        ),
                        'monthly_cash_flow' => $this->getMonthlyCashFlowData(
                            $user,
                            $startDate,
                            $endDate,
                            $walletIds,
                            $defaultCurrency
                        ),
                        'expense_by_wallet' => $this->getExpenseByWalletData(
                            $user,
                            $startDate,
                            $endDate,
                            $walletIds,
                            $defaultCurrency
                        ),
                    ],
                ];
            }
        );

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
    private function generateCacheKey(
        int $userId,
        Carbon $startDate,
        Carbon $endDate,
        array $walletIds,
        string $period
    ): string {
        $version = Cache::get('stats:user:' . $userId . ':version', 1);

        $params = [
            'start' => $startDate->toDateString(),
            'end' => $endDate->toDateString(),
            'wallets' => implode(',', $walletIds),
            'period' => $period,
            'v' => $version,
        ];

        return 'stats:user:' . $userId . ':' . md5(json_encode($params));
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
        $pattern = 'stats:user:' . $userId . ':*';

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
            Cache::increment('stats:user:' . $userId . ':version');
        }
    }

    /**
     * Calculate total balance across user's wallets converted to target currency.
     */
    private function getTotalBalance($user, array $walletIds = [], string $targetCurrency = 'USD'): float
    {
        $query = Wallet::where('user_id', $user->id);

        if (! empty($walletIds)) {
            $query->whereIn('id', $walletIds);
        }

        $wallets = $query->get(['balance', 'currency']);
        $total = 0.0;

        foreach ($wallets as $wallet) {
            $total += $this->convertCurrency(
                (float) $wallet->balance,
                $wallet->currency,
                $targetCurrency,
                $user
            );
        }

        return $total;
    }

    /**
     * Calculate user's net worth converted to target currency.
     */
    private function getNetWorth($user, array $walletIds = [], string $targetCurrency = 'USD'): float
    {
        return $this->getTotalBalance($user, $walletIds, $targetCurrency);
    }

    /**
     * Calculate average monthly amount for a transaction type with currency conversion.
     */
    private function getAverageMonthlyAmount(
        $user,
        string $type,
        array $walletIds,
        Carbon $startDate,
        Carbon $endDate,
        string $targetCurrency = 'USD'
    ): float {
        $query = Transaction::join('wallets', 'transactions.wallet_id', '=', 'wallets.id')
            ->where('transactions.user_id', $user->id)
            ->where('transactions.type', $type)
            ->whereBetween('transactions.datetime', [$startDate, $endDate])
            ->select('transactions.amount', 'transactions.datetime', 'wallets.currency');

        if (! empty($walletIds)) {
            $query->whereIn('transactions.wallet_id', $walletIds);
        }

        $transactions = $query->get();

        $monthlyTotals = [];
        foreach ($transactions as $transaction) {
            $converted = $this->convertCurrency(
                (float) $transaction->amount,
                $transaction->currency,
                $targetCurrency,
                $user
            );

            $monthKey = Carbon::parse($transaction->datetime)->format('Y-m');
            $monthlyTotals[$monthKey] = ($monthlyTotals[$monthKey] ?? 0) + $converted;
        }

        if (empty($monthlyTotals)) {
            return 0.0;
        }

        return array_sum($monthlyTotals) / count($monthlyTotals);
    }

    /**
     * Get period-over-period comparisons with currency conversion.
     */
    private function getComparisons(
        $user,
        $startDate,
        $endDate,
        array $walletIds = [],
        string $targetCurrency = 'USD'
    ): array {
        $daysInPeriod = $startDate->diffInDays($endDate);
        $previousPeriodStart = $startDate->copy()->subDays($daysInPeriod);
        $previousPeriodEnd = $startDate->copy()->subDay();

        $currentPeriodTotals = $this->getPeriodTotals($user, $startDate, $endDate, $walletIds, $targetCurrency);
        $previousPeriodTotals = $this->getPeriodTotals(
            $user,
            $previousPeriodStart,
            $previousPeriodEnd,
            $walletIds,
            $targetCurrency
        );

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

    /**
     * Calculate income, expense totals for a period with currency conversion.
     */
    private function getPeriodTotals(
        $user,
        $startDate,
        $endDate,
        array $walletIds = [],
        string $targetCurrency = 'USD'
    ): array {
        $query = Transaction::join('wallets', 'transactions.wallet_id', '=', 'wallets.id')
            ->where('transactions.user_id', $user->id)
            ->whereBetween('transactions.datetime', [$startDate, $endDate])
            ->select('transactions.amount', 'transactions.type', 'wallets.currency');

        if (! empty($walletIds)) {
            $query->whereIn('transactions.wallet_id', $walletIds);
        }

        $transactions = $query->get();

        $income = 0.0;
        $expense = 0.0;

        foreach ($transactions as $transaction) {
            $converted = $this->convertCurrency(
                (float) $transaction->amount,
                $transaction->currency,
                $targetCurrency,
                $user
            );

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

    /**
     * Get top categories with currency conversion.
     */
    private function getTopCategories(
        $user,
        $startDate,
        $endDate,
        array $walletIds = [],
        string $targetCurrency = 'USD'
    ): array {
        $query = Transaction::with(['categories', 'wallet'])
            ->where('user_id', $user->id)
            ->whereBetween('datetime', [$startDate, $endDate]);

        if (! empty($walletIds)) {
            $query->whereIn('wallet_id', $walletIds);
        }

        $transactions = $query->get();

        $incomeTransactions = $transactions->where('type', 'income');
        $expenseTransactions = $transactions->where('type', 'expense');

        return [
            'income' => $this->groupTransactionsByCategory($incomeTransactions, $user, $targetCurrency, 5),
            'expenses' => $this->groupTransactionsByCategory($expenseTransactions, $user, $targetCurrency, 5),
        ];
    }

    /**
     * Get largest transactions with currency conversion.
     */
    private function getLargestTransactions(
        $user,
        $startDate,
        $endDate,
        array $walletIds = [],
        string $targetCurrency = 'USD'
    ): array {
        $query = Transaction::with(['categories', 'wallet'])
            ->where('user_id', $user->id)
            ->whereBetween('datetime', [$startDate, $endDate]);

        if (! empty($walletIds)) {
            $query->whereIn('wallet_id', $walletIds);
        }

        $transactions = $query->get();

        $findLargest = function ($type) use ($transactions, $user, $targetCurrency) {
            $filtered = $transactions->where('type', $type);
            if ($filtered->isEmpty()) {
                return null;
            }

            $largest = null;
            $largestAmount = 0;

            foreach ($filtered as $transaction) {
                $converted = $this->convertCurrency(
                    (float) $transaction->amount,
                    $transaction->wallet->currency ?? 'USD',
                    $targetCurrency,
                    $user
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

    /**
     * Get spending trends with currency conversion.
     */
    private function getSpendingTrends(
        $user,
        $startDate,
        $endDate,
        string $period = 'month',
        array $walletIds = [],
        string $targetCurrency = 'USD'
    ): array {
        $currentPeriodData = $this->getTrendsForPeriod(
            $user,
            $startDate,
            $endDate,
            $period,
            $walletIds,
            $targetCurrency
        );

        $daysInPeriod = $startDate->diffInDays($endDate);
        $previousStartDate = $startDate->copy()->subDays($daysInPeriod + 1);
        $previousEndDate = $startDate->copy()->subDay();

        $previousPeriodData = $this->getTrendsForPeriod(
            $user,
            $previousStartDate,
            $previousEndDate,
            $period,
            $walletIds,
            $targetCurrency
        );

        return [
            'current_period' => $currentPeriodData,
            'previous_period' => $previousPeriodData,
        ];
    }

    /**
     * Get expense trends with currency conversion.
     */
    private function getTrendsForPeriod(
        $user,
        $startDate,
        $endDate,
        $period,
        array $walletIds = [],
        string $targetCurrency = 'USD'
    ): array {
        $query = Transaction::join('wallets', 'transactions.wallet_id', '=', 'wallets.id')
            ->where('transactions.user_id', $user->id)
            ->whereBetween('transactions.datetime', [$startDate, $endDate])
            ->where('transactions.type', 'expense')
            ->select('transactions.amount', 'transactions.datetime', 'wallets.currency');

        if (! empty($walletIds)) {
            $query->whereIn('transactions.wallet_id', $walletIds);
        }

        $transactions = $query->get();

        $groupedData = [];
        foreach ($transactions as $transaction) {
            $converted = $this->convertCurrency(
                (float) $transaction->amount,
                $transaction->currency,
                $targetCurrency,
                $user
            );

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

    /**
     * Get expense distribution by classification with currency conversion.
     */
    private function getCategoryDistribution(
        $user,
        $startDate,
        $endDate,
        array $walletIds = [],
        string $targetCurrency = 'USD'
    ): array {
        $query = Transaction::with(['categories', 'wallet'])
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

        $total = 0;

        foreach ($transactions as $transaction) {
            $converted = $this->convertCurrency(
                (float) $transaction->amount,
                $transaction->wallet->currency ?? 'USD',
                $targetCurrency,
                $user
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
        $text = $name . ' ' . $slug;

        // Essential: housing, utilities, food, healthcare, transport, insurance
        // @phpcs:ignore
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
     * Get period summary with currency conversion.
     */
    private function getPeriodSummary(
        $user,
        $startDate,
        $endDate,
        array $walletIds = [],
        string $targetCurrency = 'USD'
    ): array {
        $today = Carbon::now();
        $daysRemaining = $today->diffInDays($endDate, false);
        $daysElapsed = $startDate->diffInDays($today);
        $totalDays = $startDate->diffInDays($endDate);

        $totals = $this->getPeriodTotals($user, $startDate, $today, $walletIds, $targetCurrency);

        $currentIncome = $totals['income'];
        $currentExpenses = $totals['expense'];

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
     * Get party data with currency conversion.
     */
    private function getPartyData(
        $user,
        $startDate,
        $endDate,
        array $walletIds = [],
        string $type = 'expense',
        string $targetCurrency = 'USD'
    ): array {
        $query = Transaction::with(['party', 'wallet'])
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->whereBetween('datetime', [$startDate, $endDate]);

        if (! empty($walletIds)) {
            $query->whereIn('wallet_id', $walletIds);
        }

        $transactions = $query->get();

        $partyData = [];
        foreach ($transactions as $transaction) {
            $converted = $this->convertCurrency(
                (float) $transaction->amount,
                $transaction->wallet->currency ?? 'USD',
                $targetCurrency,
                $user
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

    /**
     * Get category spending data with currency conversion.
     */
    private function getCategorySpendingData(
        $user,
        $startDate,
        $endDate,
        array $walletIds,
        string $type = 'expense',
        string $targetCurrency = 'USD'
    ): array {
        $query = Transaction::with(['categories', 'wallet'])
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->whereBetween('datetime', [$startDate, $endDate]);

        if (! empty($walletIds)) {
            $query->whereIn('wallet_id', $walletIds);
        }

        return $this->groupTransactionsByCategory($query->get(), $user, $targetCurrency);
    }

    /**
     * Group transactions by category with currency conversion.
     */
    private function groupTransactionsByCategory(
        $transactions,
        $user,
        string $targetCurrency,
        ?int $limit = null
    ): array {
        $categoryData = [];

        foreach ($transactions as $transaction) {
            $converted = $this->convertCurrency(
                (float) $transaction->amount,
                $transaction->wallet->currency ?? 'USD',
                $targetCurrency,
                $user
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
     * Get monthly cash flow data with currency conversion.
     */
    private function getMonthlyCashFlowData(
        $user,
        $startDate,
        $endDate,
        array $walletIds = [],
        string $targetCurrency = 'USD'
    ): array {
        $query = Transaction::join('wallets', 'transactions.wallet_id', '=', 'wallets.id')
            ->where('transactions.user_id', $user->id)
            ->whereBetween('transactions.datetime', [$startDate, $endDate])
            ->select('transactions.amount', 'transactions.type', 'transactions.datetime', 'wallets.currency');

        if (! empty($walletIds)) {
            $query->whereIn('transactions.wallet_id', $walletIds);
        }

        $transactions = $query->get();

        $monthlyData = [];
        foreach ($transactions as $transaction) {
            $converted = $this->convertCurrency(
                (float) $transaction->amount,
                $transaction->currency,
                $targetCurrency,
                $user
            );

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

    /**
     * Get expense by wallet with currency conversion.
     */
    private function getExpenseByWalletData(
        $user,
        $startDate,
        $endDate,
        array $walletIds = [],
        string $targetCurrency = 'USD'
    ): array {
        $query = Transaction::with('wallet')
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereBetween('datetime', [$startDate, $endDate]);

        if (! empty($walletIds)) {
            $query->whereIn('wallet_id', $walletIds);
        }

        $transactions = $query->get();

        $walletData = [];
        foreach ($transactions as $transaction) {
            $converted = $this->convertCurrency(
                (float) $transaction->amount,
                $transaction->wallet->currency ?? 'USD',
                $targetCurrency,
                $user
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
}
