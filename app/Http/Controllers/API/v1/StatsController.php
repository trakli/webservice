<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Models\Transaction;
use App\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        // TODO: Implement other stats (comparisons, top categories, etc.)

        return response()->json([
            'data' => [
                'overview' => $overview,
                'comparisons' => $this->getComparisons($user, $startDate, $endDate, $walletIds),
                'top_categories' => $this->getTopCategories($user, $startDate, $endDate, $walletIds),
                'largest_transactions' => $this->getLargestTransactions($user, $startDate, $endDate, $walletIds),
                'spending_trends' => $this->getSpendingTrends($user, $startDate, $endDate, $request->input('period', 'month')),
                'category_distribution' => $this->getCategoryDistribution($user, $startDate, $endDate, $walletIds),
                'period_summary' => $this->getPeriodSummary($user, $startDate, $endDate, $walletIds),
                'charts' => [
                    'party_spending' => $this->getPartySpendingData($user, $startDate, $endDate, $walletIds),
                    'category_spending' => $this->getCategorySpendingData($user, $startDate, $endDate, $walletIds, 'expense'),
                    'income_sources' => $this->getCategorySpendingData($user, $startDate, $endDate, $walletIds, 'income'),
                    'monthly_cash_flow' => $this->getMonthlyCashFlowData($user, $startDate, $endDate, $walletIds),
                    'expense_by_wallet' => $this->getExpenseByWalletData($user, $startDate, $endDate, $walletIds),
                ],
            ],
        ]);
    }

    private function getTotalBalance($user, array $walletIds = []): float
    {
        $query = Wallet::where('user_id', $user->id);

        if (! empty($walletIds)) {
            $query->whereIn('id', $walletIds);
        }

        return (float) $query->sum('balance');
    }

    private function getNetWorth($user, array $walletIds = []): float
    {
        // For now, just return total balance
        // In the future, this could include other assets and liabilities
        return $this->getTotalBalance($user, $walletIds);
    }

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

    private function getTopCategories($user, $startDate, $endDate, array $walletIds = []): array
    {
        $query = Transaction::with('categories')
            ->where('user_id', $user->id)
            ->whereBetween('datetime', [$startDate, $endDate]);

        if (! empty($walletIds)) {
            $query->whereIn('wallet_id', $walletIds);
        }

        // Get all transactions with their categories
        $transactions = $query->get();

        $categories = [
            'income' => [],
            'expense' => [],
        ];

        // Process transactions and group by category and type
        foreach ($transactions as $transaction) {
            $type = $transaction->type === 'income' ? 'income' : 'expenses';
            $category = $transaction->categories->first();

            if (! $category) {
                $categoryId = 'uncategorized';
                $categoryName = 'Uncategorized';
            } else {
                $categoryId = $category->id;
                $categoryName = $category->name;
            }

            if (! isset($categories[$type][$categoryId])) {
                $categories[$type][$categoryId] = [
                    'id' => $categoryId,
                    'name' => $categoryName,
                    'amount' => 0,
                ];
            }

            $categories[$type][$categoryId]['amount'] += $transaction->amount;
        }

        // Calculate percentages and sort by amount
        $result = [];

        foreach (['income', 'expense'] as $type) {
            $typeKey = $type === 'expense' ? 'expenses' : 'income';
            $typeTotal = array_sum(array_column($categories[$typeKey] ?? [], 'amount'));

            $sorted = collect($categories[$typeKey] ?? [])
                ->sortByDesc('amount')
                ->take(5) // Limit to top 5 categories
                ->map(function ($item) use ($typeTotal) {
                    $item['percentage'] = $typeTotal > 0 ? ($item['amount'] / $typeTotal) * 100 : 0;
                    $item['amount'] = (float) $item['amount'];

                    return $item;
                })
                ->values()
                ->toArray();

            $result[$typeKey] = $sorted;
        }

        return $result;
    }

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

        // TODO: Define essential category IDs in config or database
        $distribution = [
            'essential' => 0,
            'non_essential' => 0,
            'savings' => 0,
            'investments' => 0,
        ];

        $total = $transactions->sum('amount');

        if ($total > 0) {
            // TODO: Load essential category IDs from config/database
            $essentialCategories = config('finance.essential_categories', [1, 2]);

            foreach ($transactions as $transaction) {
                $category = $transaction->categories->first();

                if ($category) {
                    if (in_array($category->id, $essentialCategories)) {
                        $distribution['essential'] += $transaction->amount;
                    } else {
                        $distribution['non_essential'] += $transaction->amount;
                    }
                } else {
                    $distribution['non_essential'] += $transaction->amount;
                }
            }

            // Calculate percentages
            foreach ($distribution as $key => $amount) {
                $distribution[$key] = ($amount / $total) * 100;
            }
        }

        return $distribution;
    }

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
     * Get party spending data for charts
     */
    private function getPartySpendingData($user, $startDate, $endDate, array $walletIds = []): array
    {
        $query = Transaction::with('party')
            ->where('user_id', $user->id)
            ->where('type', 'expense')
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
     * Get category spending data for charts
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

        $transactions = $query->get();

        // Group by category using array
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

        // Calculate total and percentages
        $totalAmount = array_sum(array_column($categoryData, 'amount'));

        return collect($categoryData)->map(function ($item) use ($totalAmount) {
            $item['amount'] = (float) $item['amount'];
            $item['percentage'] = $totalAmount > 0 ? ($item['amount'] / $totalAmount) * 100 : 0;

            return $item;
        })->sortByDesc('amount')->values()->toArray();
    }

    /**
     * Get monthly cash flow data for line/bar charts
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
     * Get expense distribution by wallet
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
