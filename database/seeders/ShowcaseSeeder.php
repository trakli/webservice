<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
use App\Services\BudgetProgressService;
use App\Services\DuplicateDetectionService;
use App\Services\TransferService;
use App\Support\ConfigurationKeys;
use App\Types\TransactionSuggestion;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Whilesmart\ModelConfiguration\Enums\ConfigValueType;

class ShowcaseSeeder extends Seeder
{
    public function run(User $user): void
    {
        $user->setConfigValue(ConfigurationKeys::DEFAULT_CURRENCY, 'EUR', ConfigValueType::String);
        $user->setConfigValue(ConfigurationKeys::DEFAULT_LANG, 'en', ConfigValueType::String);
        $user->setConfigValue(ConfigurationKeys::ONBOARDING_COMPLETE, true, ConfigValueType::Boolean);
        $user->setConfigValue(ConfigurationKeys::MANUAL_EXCHANGE_RATES, [
            'USD-EUR' => 0.92, 'XAF-EUR' => 1 / 655.957,
        ], ConfigValueType::Json);

        $user->wallets()->where('name', __('Main Wallet'))->first()?->update(['name' => 'Main Checking']);
        $wallets = [];
        foreach (
            [
            ['Main Checking', 'bank', 'EUR', 4200],
            ['Cash', 'cash', 'EUR', 450],
            ['Dollar Savings', 'bank', 'USD', 3200],
            ['Mobile Money', 'mobile', 'XAF', 95000],
            ['Credit Card', 'credit_card', 'EUR', 0],
            ] as [$name, $type, $currency, $balance]
        ) {
            $wallets[$name] = $user->wallets()->updateOrCreate(['name' => $name], compact('type', 'currency', 'balance'));
        }
        $categories = [];
        foreach (
            ['Salary', 'Freelance', 'Food & Dining', 'Transportation', 'Housing', 'Utilities',
            'Subscriptions', 'Healthcare', 'Personal Care', 'Shopping', 'Family'] as $name
        ) {
            $type = in_array($name, ['Salary', 'Freelance'], true) ? 'income' : 'expense';
            $categories[$name] = Category::ownedBy($user)->where('name', $name)->where('type', $type)->first()
                ?? Category::createSeeded($user, compact('name', 'type'));
        }
        $parties = [];
        foreach (
            ['Northwind Studio', 'Bright Pixel Agency', 'Atlas Design Co', 'Lindenhof Properties',
            'FreshMart', 'Green Basket Market', 'Aldi', 'Corner Coffee', 'City Metro', 'Bolt', 'Trattoria Roma',
            'Central Pharmacy', 'Urban Gym', 'Netflix', 'Spotify', 'Volta Energy', 'FiberNet', 'Orange',
            'Marché Central', 'Amara Okafor', 'Leo Fischer'] as $name
        ) {
            $parties[$name] = $user->parties()->create([
                'name' => $name, 'type' => in_array($name, ['Amara Okafor', 'Leo Fischer'], true) ? 'individual' : 'business',
            ]);
        }

        $record = function (
            Carbon $date,
            string $category,
            ?string $party,
            string $wallet,
            float $amount,
            string $description,
            array $extra = []
        ) use (
            $user,
            $wallets,
            $categories,
            $parties
        ) {
            $transaction = $user->transactions()->create(array_merge([
                'wallet_id' => $wallets[$wallet]->id, 'party_id' => $party ? $parties[$party]->id : null,
                'type' => in_array($category, ['Salary', 'Freelance'], true) ? 'income' : 'expense',
                'amount' => $amount, 'description' => $description, 'datetime' => $date,
            ], $extra));
            $transaction->categories()->attach($categories[$category]->id);

            return $transaction;
        };

        $start = Carbon::today()->subMonthsNoOverflow(6);
        $index = 0;
        for ($month = $start->copy()->startOfMonth(); $month->lte(Carbon::today()); $month->addMonth(), $index++) {
            $date = fn (int $day) => $month->copy()->day(min($day, $month->daysInMonth))->startOfDay();
            $entries = [
                [1, 'Salary', 'Northwind Studio', 'Main Checking', 3420, 'Monthly salary'],
                [1, 'Housing', 'Lindenhof Properties', 'Main Checking', 1150, 'Rent'],
                [1, 'Subscriptions', 'Netflix', 'Main Checking', 13.99, 'Netflix'],
                [1, 'Subscriptions', 'Spotify', 'Main Checking', 10.99, 'Spotify Premium'],
                [4, 'Utilities', 'Volta Energy', 'Main Checking', 62.40 + $index * 1.7, 'Electricity'],
                [5, 'Utilities', 'FiberNet', 'Main Checking', 39.99, 'Home internet'],
                [6, 'Freelance', 'Bright Pixel Agency', 'Main Checking', 480 + $index * 35, 'Design invoice'],
                [18, 'Freelance', 'Atlas Design Co', 'Dollar Savings', 520 + $index * 20, 'Website illustrations'],
                [1, 'Transportation', 'City Metro', 'Main Checking', 49, 'Monthly metro pass'],
                [1, 'Shopping', null, 'Credit Card', 119.90 + $index * 3, 'New trainers'],
                [6, 'Personal Care', 'Urban Gym', 'Main Checking', 34.90, 'Gym membership'],
                [7, 'Healthcare', 'Central Pharmacy', 'Cash', 14.60, 'Allergy tablets'],
                [9, 'Utilities', 'Orange', 'Mobile Money', 2500, 'Airtime'],
                [14, 'Food & Dining', 'Marché Central', 'Mobile Money', 12500, 'Market groceries'],
                [20, 'Family', 'Leo Fischer', 'Mobile Money', 30000, 'For Leo'],
            ];
            for ($i = 0; $i < 28; $i++) {
                [$category, $party, $wallet, $amount, $description] = [
                    ['Food & Dining', 'FreshMart', 'Main Checking', 43.25, 'Weekly groceries'],
                    ['Food & Dining', 'Corner Coffee', 'Cash', 3.80, 'Flat white'],
                    ['Transportation', 'City Metro', 'Main Checking', 8.70, 'Metro tickets'],
                    ['Food & Dining', 'Green Basket Market', 'Cash', 22.40, 'Fruit and vegetables'],
                    ['Food & Dining', 'Trattoria Roma', 'Credit Card', 32.50, 'Dinner with friends'],
                    ['Food & Dining', 'Aldi', 'Main Checking', 28.65, 'Groceries'],
                    ['Transportation', 'Bolt', 'Main Checking', 12.60, 'Ride home'],
                ][$i % 7];
                $entries[] = [1 + $i, $category, $party, $wallet, round($amount + ($i % 3) * 0.35 + $index * 0.2, 2), $description];
            }
            if ((int) $month->month % 2 === 0) {
                $purchase = ['Laptop repair', 'Flight to Lisbon', 'Birthday gift'][(int) $month->month % 3];
                $entries[] = [16, 'Shopping', null, 'Credit Card', 185 + $index * 12, $purchase];
            }
            foreach ($entries as [$day, $category, $party, $wallet, $amount, $description]) {
                if ($date($day)->betweenIncluded($start, Carbon::today())) {
                    $record($date($day), $category, $party, $wallet, $amount, $description);
                }
            }
            if ($date(1)->betweenIncluded($start, Carbon::today())) {
                foreach (
                    [['Cash', 180, 180, 1], ['Dollar Savings', 250, 271.75, 1.087],
                    ['Mobile Money', 100, 65595.7, 655.957], ['Credit Card', 220, 220, 1]] as [$wallet, $send, $receive, $rate]
                ) {
                    app(TransferService::class)->transfer(
                        $send,
                        $wallets['Main Checking']->fresh(),
                        $receive,
                        $wallets[$wallet]->fresh(),
                        $user,
                        $rate,
                        datetime: $date(1)->toDateTimeString()
                    );
                }
            }
        }
        $record(Carbon::today()->subDays(24), 'Family', 'Amara Okafor', 'Main Checking', 240, 'Amara rent advance', ['intent' => 'loan_given']);
        $record(
            Carbon::today()->subDays(10),
            'Family',
            'Amara Okafor',
            'Main Checking',
            90,
            'Amara paid back',
            ['type' => 'income', 'intent' => 'loan_collected']
        );

        foreach (['Food & Dining' => 80, 'Transportation' => 55, 'Shopping' => 120, 'Subscriptions' => 100] as $name => $percent) {
            $budget = $user->budgets()->create([
                'name' => $name, 'amount' => 1, 'currency' => 'EUR', 'period_type' => 'monthly',
                'start_date' => Carbon::today()->startOfMonth(), 'rollover_enabled' => false,
                'threshold_percent' => 80, 'forecast_alerts_enabled' => false, 'is_active' => true,
            ]);
            $budget->categories()->attach($categories[$name]->id);
            $spent = app(BudgetProgressService::class)->compute($budget)['net_spent'];
            $budget->update(['amount' => round($spent * 100 / $percent, 2)]);
            $budget->markAsSynced();
        }
        foreach (['Rent', 'Netflix subscription', 'Electricity bill'] as $title) {
            $reminder = $user->reminders()->create([
                'title' => $title, 'type' => 'bill_due', 'trigger_at' => Carbon::today()->startOfMonth(),
                'timezone' => 'Europe/Berlin', 'status' => 'active', 'priority' => 1,
            ]);
            $reminder->markAsSynced();
            $reminder->calculateNextTrigger();
            $reminder->save();
        }
        $this->import($user, $wallets, $categories, $parties);
    }

    private function import(User $user, array $wallets, array $categories, array $parties): void
    {
        $existing = $user->transactions()->whereNull('transfer_id')->where('description', 'Rent')->latest('datetime')->firstOrFail();
        $suggestions = [];
        for ($i = 0; $i < 12; $i++) {
            $suggestions[] = new TransactionSuggestion(
                amount: $i === 0 ? (float) $existing->amount : 75.13 + $i * 7.31,
                currency: 'EUR',
                type: 'expense',
                party: $i === 0 ? 'Lindenhof Properties' : 'FreshMart',
                wallet: 'Main Checking',
                category: $i === 0 ? 'Housing' : 'Food & Dining',
                description: $i === 0 ? 'Rent' : 'Card purchase FreshMart',
                date: $i === 0 ? Carbon::parse($existing->datetime)->toDateString() : Carbon::today()->subDays($i)->toDateString(),
                documentType: 'bank_statement',
                partyId: $parties[$i === 0 ? 'Lindenhof Properties' : 'FreshMart']->id,
                categoryId: $categories[$i === 0 ? 'Housing' : 'Food & Dining']->id,
                walletId: $wallets['Main Checking']->id,
            );
        }
        $duplicates = app(DuplicateDetectionService::class)->checkBatch($suggestions, $user);
        $rows = [];
        foreach ($suggestions as $i => $suggestion) {
            $rows[] = array_merge($suggestion->toArray(), [
                'counterparty' => $suggestion->party, 'duplicate' => $duplicates[$i]?->toArray(),
            ]);
        }
        $user->importSessions()->create([
            'file_name' => 'Checking statement ' . Carbon::today()->format('Y-m') . '.pdf',
            'file_type' => 'pdf', 'document_type' => 'bank_statement', 'processor' => 'RemoteDocumentProcessor',
            'status' => 'ready', 'suggestions' => $rows,
            'metadata' => ['proposed_rules' => [], 'total_suggestions' => 12, 'duplicates_found' => count(array_filter($duplicates))],
        ]);
    }
}
