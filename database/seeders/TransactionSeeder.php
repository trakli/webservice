<?php

namespace Database\Seeders;

use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class TransactionSeeder extends Seeder
{
    private array $incomeTemplates = [
        ['category' => 'Salary', 'party' => 'Acme Corporation', 'amount_range' => [2500, 4000], 'description' => 'Monthly salary', 'weight' => 1],
        ['category' => 'Freelance', 'party' => 'TechStart Inc', 'amount_range' => [300, 1200], 'description' => 'Freelance project', 'weight' => 2],
        ['category' => 'Freelance', 'party' => 'Digital Solutions Ltd', 'amount_range' => [200, 800], 'description' => 'Contract work', 'weight' => 2],
        ['category' => 'Investments', 'party' => 'Investment Portfolio', 'amount_range' => [50, 300], 'description' => 'Dividend payment', 'weight' => 1],
        ['category' => 'Gifts', 'party' => 'Family', 'amount_range' => [20, 200], 'description' => 'Gift received', 'weight' => 1],
        ['category' => 'Refunds', 'party' => 'Amazon', 'amount_range' => [10, 100], 'description' => 'Refund', 'weight' => 1],
    ];

    private array $expenseTemplates = [
        ['category' => 'Groceries', 'party' => 'SuperMart', 'amount_range' => [30, 150], 'description' => 'Groceries', 'weight' => 15],
        ['category' => 'Rent', 'party' => 'City Properties', 'amount_range' => [800, 1400], 'description' => 'Monthly rent', 'weight' => 1],
        ['category' => 'Utilities', 'party' => 'Power Company', 'amount_range' => [40, 120], 'description' => 'Electricity bill', 'weight' => 2],
        ['category' => 'Utilities', 'party' => 'Water Works', 'amount_range' => [20, 60], 'description' => 'Water bill', 'weight' => 2],
        ['category' => 'Utilities', 'party' => 'Internet Plus', 'amount_range' => [35, 80], 'description' => 'Internet', 'weight' => 2],
        ['category' => 'Transport', 'party' => 'Shell Station', 'amount_range' => [25, 70], 'description' => 'Fuel', 'weight' => 8],
        ['category' => 'Transport', 'party' => 'Uber', 'amount_range' => [8, 35], 'description' => 'Ride', 'weight' => 10],
        ['category' => 'Dining', 'party' => 'Pizza Palace', 'amount_range' => [12, 45], 'description' => 'Dinner', 'weight' => 8],
        ['category' => 'Dining', 'party' => 'Café Express', 'amount_range' => [4, 12], 'description' => 'Coffee', 'weight' => 20],
        ['category' => 'Entertainment', 'party' => 'Netflix', 'amount_range' => [12, 18], 'description' => 'Subscription', 'weight' => 2],
        ['category' => 'Entertainment', 'party' => 'Spotify', 'amount_range' => [8, 12], 'description' => 'Music subscription', 'weight' => 2],
        ['category' => 'Health', 'party' => 'City Pharmacy', 'amount_range' => [15, 80], 'description' => 'Medicine', 'weight' => 3],
        ['category' => 'Shopping', 'party' => 'Fashion Store', 'amount_range' => [30, 150], 'description' => 'Clothing', 'weight' => 4],
        ['category' => 'Shopping', 'party' => 'Amazon', 'amount_range' => [15, 120], 'description' => 'Online purchase', 'weight' => 6],
        ['category' => 'Education', 'party' => 'Udemy', 'amount_range' => [10, 80], 'description' => 'Course', 'weight' => 2],
        ['category' => 'Insurance', 'party' => 'State Insurance', 'amount_range' => [80, 250], 'description' => 'Insurance premium', 'weight' => 1],
    ];

    public function run(): void
    {
        $users = User::all();

        foreach ($users as $user) {
            $this->createTransactionsForUser($user);
        }
    }

    private function createTransactionsForUser(User $user): void
    {
        $categories = $user->categories->keyBy('name');
        $parties = $user->parties->keyBy('name');
        $wallets = $user->wallets;

        if ($categories->isEmpty() || $parties->isEmpty() || $wallets->isEmpty()) {
            return;
        }

        $allTransactions = [];

        // First, create some transactions for the current week (guaranteed recent data)
        $this->createRecentTransactions($allTransactions, $categories, $parties);

        // Generate transactions for the past 3 months
        for ($month = 0; $month < 3; $month++) {
            $monthStart = Carbon::now()->subMonths($month)->startOfMonth();
            $monthEnd = Carbon::now()->subMonths($month)->endOfMonth();

            // Ensure we don't go past today
            if ($monthEnd->isFuture()) {
                $monthEnd = Carbon::now();
            }

            // Monthly salary (1-2 per month, early in month)
            if (rand(1, 10) <= 9) { // 90% chance of salary
                $salaryTemplate = $this->incomeTemplates[0];
                if ($categories->has($salaryTemplate['category']) && $parties->has($salaryTemplate['party'])) {
                    $allTransactions[] = [
                        'type' => 'income',
                        'template' => $salaryTemplate,
                        'date' => $monthStart->copy()->addDays(rand(0, 5)),
                        'category' => $categories->get($salaryTemplate['category']),
                        'party' => $parties->get($salaryTemplate['party']),
                    ];
                }
            }

            // Random other income (0-3 per month)
            $otherIncomeCount = rand(0, 3);
            for ($i = 0; $i < $otherIncomeCount; $i++) {
                $template = $this->getWeightedTemplate($this->incomeTemplates, 1); // Skip salary
                if ($categories->has($template['category']) && $parties->has($template['party'])) {
                    $allTransactions[] = [
                        'type' => 'income',
                        'template' => $template,
                        'date' => $this->randomDateBetween($monthStart, $monthEnd),
                        'category' => $categories->get($template['category']),
                        'party' => $parties->get($template['party']),
                    ];
                }
            }

            // Monthly bills (rent, subscriptions, insurance - once per month)
            $monthlyBills = ['Rent', 'Netflix', 'Spotify', 'Insurance'];
            foreach ($this->expenseTemplates as $template) {
                if (in_array($template['category'], $monthlyBills) ||
                    in_array($template['party'], ['City Properties', 'Netflix', 'Spotify', 'State Insurance'])) {
                    if ($categories->has($template['category']) && $parties->has($template['party'])) {
                        // Skip some months randomly for non-essential subscriptions
                        if ($template['party'] !== 'City Properties' && rand(1, 10) <= 2) {
                            continue;
                        }
                        $allTransactions[] = [
                            'type' => 'expense',
                            'template' => $template,
                            'date' => $monthStart->copy()->addDays(rand(0, 10)),
                            'category' => $categories->get($template['category']),
                            'party' => $parties->get($template['party']),
                        ];
                    }
                }
            }

            // Daily/frequent expenses (40-80 per month - people spend a lot!)
            $dailyExpenseCount = rand(40, 80);
            for ($i = 0; $i < $dailyExpenseCount; $i++) {
                $template = $this->getWeightedTemplate($this->expenseTemplates);

                // Skip monthly bills in random selection
                if (in_array($template['party'], ['City Properties', 'Netflix', 'Spotify', 'State Insurance'])) {
                    continue;
                }

                if ($categories->has($template['category']) && $parties->has($template['party'])) {
                    $allTransactions[] = [
                        'type' => 'expense',
                        'template' => $template,
                        'date' => $this->randomDateBetween($monthStart, $monthEnd),
                        'category' => $categories->get($template['category']),
                        'party' => $parties->get($template['party']),
                    ];
                }
            }
        }

        // Shuffle all transactions to mix income and expenses
        shuffle($allTransactions);

        // Sort by date to maintain chronological order
        usort($allTransactions, fn($a, $b) => $a['date']->timestamp - $b['date']->timestamp);

        // Create the transactions
        foreach ($allTransactions as $txnData) {
            $this->createTransaction(
                $user,
                $txnData['category'],
                $txnData['party'],
                $this->selectWallet($txnData['template']['category'], $wallets),
                $txnData['type'],
                $txnData['template']['amount_range'],
                $txnData['template']['description'],
                $txnData['date']
            );
        }

    }

    private function createRecentTransactions(array &$allTransactions, $categories, $parties): void
    {
        $today = Carbon::now();
        $weekStart = $today->copy()->startOfWeek();

        // Create 10-20 transactions in the current week
        $recentCount = rand(10, 20);
        for ($i = 0; $i < $recentCount; $i++) {
            $template = $this->getWeightedTemplate($this->expenseTemplates);

            // Skip monthly bills
            if (in_array($template['party'], ['City Properties', 'Netflix', 'Spotify', 'State Insurance'])) {
                continue;
            }

            if ($categories->has($template['category']) && $parties->has($template['party'])) {
                $daysOffset = rand(0, min(6, $today->diffInDays($weekStart)));
                $txnDate = $weekStart->copy()->addDays($daysOffset);

                // Don't create future transactions
                if ($txnDate->isAfter($today)) {
                    $txnDate = $today->copy();
                }

                $allTransactions[] = [
                    'type' => 'expense',
                    'template' => $template,
                    'date' => $txnDate,
                    'category' => $categories->get($template['category']),
                    'party' => $parties->get($template['party']),
                ];
            }
        }

        // Maybe add a recent income (freelance payment or similar)
        if (rand(1, 3) === 1) {
            $incomeTemplate = $this->incomeTemplates[rand(1, count($this->incomeTemplates) - 1)];
            if ($categories->has($incomeTemplate['category']) && $parties->has($incomeTemplate['party'])) {
                $allTransactions[] = [
                    'type' => 'income',
                    'template' => $incomeTemplate,
                    'date' => $this->randomDateBetween($weekStart, $today),
                    'category' => $categories->get($incomeTemplate['category']),
                    'party' => $parties->get($incomeTemplate['party']),
                ];
            }
        }
    }

    private function getWeightedTemplate(array $templates, int $skipFirst = 0): array
    {
        $weighted = [];
        foreach (array_slice($templates, $skipFirst) as $template) {
            $weight = $template['weight'] ?? 1;
            for ($i = 0; $i < $weight; $i++) {
                $weighted[] = $template;
            }
        }
        return $weighted[array_rand($weighted)];
    }

    private function randomDateBetween(Carbon $start, Carbon $end): Carbon
    {
        $diffInSeconds = $end->timestamp - $start->timestamp;
        $randomSeconds = rand(0, max(0, $diffInSeconds));
        return $start->copy()->addSeconds($randomSeconds);
    }

    private function selectWallet(string $category, $wallets)
    {
        $primaryWallet = $wallets->firstWhere('name', 'Main Checking') ?? $wallets->first();
        $cashWallet = $wallets->firstWhere('name', 'Cash Wallet') ?? $primaryWallet;
        $creditCard = $wallets->firstWhere('name', 'Credit Card') ?? $primaryWallet;
        $mobileWallet = $wallets->firstWhere('name', 'Mobile Money') ?? $primaryWallet;

        return match ($category) {
            'Dining' => collect([$cashWallet, $mobileWallet, $primaryWallet])->random(),
            'Transport' => collect([$cashWallet, $mobileWallet])->random(),
            'Shopping' => collect([$creditCard, $primaryWallet])->random(),
            'Entertainment' => $creditCard,
            'Groceries' => collect([$primaryWallet, $cashWallet, $mobileWallet])->random(),
            default => $primaryWallet,
        };
    }

    private function createTransaction($user, $category, $party, $wallet, $type, $amountRange, $description, $date): void
    {
        $amount = rand($amountRange[0] * 100, $amountRange[1] * 100) / 100;

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'party_id' => $party->id,
            'wallet_id' => $wallet->id,
            'amount' => $amount,
            'type' => $type,
            'description' => $description,
            'datetime' => $date,
        ]);

        $transaction->categories()->attach($category->id);
    }
}
