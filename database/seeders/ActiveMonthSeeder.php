<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\TransferService;
use App\Support\ConfigurationKeys;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Whilesmart\ModelConfiguration\Enums\ConfigValueType;

class ActiveMonthSeeder extends Seeder
{
    private array $wallets = [
        'Main Checking' => ['type' => 'bank', 'currency' => 'EUR', 'opening' => 1850, 'description' => 'Salary account'],
        'Cash' => ['type' => 'cash', 'currency' => 'EUR', 'opening' => 380, 'description' => 'Pocket money'],
        'Dollar Savings' => ['type' => 'bank', 'currency' => 'USD', 'opening' => 3200, 'description' => 'Savings kept in dollars'],
    ];

    private array $categories = [
        ['type' => 'income', 'name' => 'Salary', 'description' => 'Regular employment income'],
        ['type' => 'income', 'name' => 'Freelance', 'description' => 'Contract/gig work'],
        ['type' => 'expense', 'name' => 'Food & Dining', 'description' => 'Groceries, restaurants, takeout'],
        ['type' => 'expense', 'name' => 'Transportation', 'description' => 'Fuel, public transit, ride-share, parking'],
        ['type' => 'expense', 'name' => 'Housing', 'description' => 'Rent, mortgage, repairs, maintenance'],
        ['type' => 'expense', 'name' => 'Utilities', 'description' => 'Electric, water, gas, internet, phone'],
        ['type' => 'expense', 'name' => 'Healthcare', 'description' => 'Medical, dental, pharmacy, insurance'],
        ['type' => 'expense', 'name' => 'Shopping', 'description' => 'Clothing, electronics, household items'],
        ['type' => 'expense', 'name' => 'Subscriptions', 'description' => 'Recurring services, memberships'],
        ['type' => 'expense', 'name' => 'Personal Care', 'description' => 'Haircuts, cosmetics, gym'],
    ];

    private array $parties = [
        ['name' => 'Northwind Studio', 'type' => 'business', 'description' => 'Employer'],
        ['name' => 'Bright Pixel Agency', 'type' => 'business', 'description' => 'Freelance client'],
        ['name' => 'Lindenhof Properties', 'type' => 'business', 'description' => 'Landlord'],
        ['name' => 'Corner Coffee', 'type' => 'business', 'description' => 'Coffee near the office'],
        ['name' => 'FreshMart', 'type' => 'business', 'description' => 'Supermarket'],
        ['name' => 'Green Basket Market', 'type' => 'business', 'description' => 'Saturday market'],
        ['name' => 'City Metro', 'type' => 'business', 'description' => 'Public transport'],
        ['name' => 'Bolt', 'type' => 'business', 'description' => 'Rides'],
        ['name' => 'Trattoria Roma', 'type' => 'business', 'description' => 'Restaurant'],
        ['name' => 'Netflix', 'type' => 'business', 'description' => 'Streaming'],
        ['name' => 'Spotify', 'type' => 'business', 'description' => 'Music streaming'],
        ['name' => 'Urban Gym', 'type' => 'business', 'description' => 'Gym membership'],
        ['name' => 'Volta Energy', 'type' => 'business', 'description' => 'Electricity'],
        ['name' => 'FiberNet', 'type' => 'business', 'description' => 'Home internet'],
        ['name' => 'Central Pharmacy', 'type' => 'business', 'description' => 'Pharmacy'],
        ['name' => 'Amara Okafor', 'type' => 'individual', 'description' => 'Flatmate'],
    ];

    private function entries(): array
    {
        $entries = [
            [1, 'income', 'Salary', 'Northwind Studio', 'Main Checking', 3420.00, 'Monthly salary'],
            [1, 'expense', 'Housing', 'Lindenhof Properties', 'Main Checking', 1150.00, 'Rent'],
            [3, 'expense', 'Subscriptions', 'Netflix', 'Main Checking', 13.99, 'Netflix subscription'],
            [4, 'expense', 'Utilities', 'Volta Energy', 'Main Checking', 64.20, 'Electricity bill'],
            [5, 'expense', 'Personal Care', 'Urban Gym', 'Main Checking', 34.90, 'Gym membership'],
            [6, 'expense', 'Utilities', 'FiberNet', 'Main Checking', 39.99, 'Home internet'],
            [9, 'expense', 'Subscriptions', 'Spotify', 'Main Checking', 10.99, 'Spotify Premium'],
            [12, 'income', 'Freelance', 'Bright Pixel Agency', 'Dollar Savings', 640.00, 'Logo design project'],
            [14, 'expense', 'Healthcare', 'Central Pharmacy', 'Cash', 12.40, 'Allergy tablets'],
            [16, 'expense', 'Shopping', null, 'Main Checking', 59.90, 'Running shoes'],
            [19, 'income', null, 'Amara Okafor', 'Cash', 45.00, 'Share of the electricity bill'],
            [22, 'expense', 'Shopping', null, 'Main Checking', 24.50, 'Birthday present for Leo'],
            [11, 'expense', 'Personal Care', null, 'Cash', 22.00, 'Haircut'],
            [8, 'expense', 'Utilities', null, 'Main Checking', 15.00, 'Phone plan'],
            [18, 'expense', 'Transportation', null, 'Cash', 28.00, 'Bike repair'],
            [10, 'expense', 'Food & Dining', null, 'Cash', 6.40, 'Bakery'],
            [23, 'expense', 'Food & Dining', null, 'Cash', 5.90, 'Bakery'],
        ];

        $coffee = [
            1 => 3.20, 2 => 3.80, 4 => 4.10, 5 => 3.50, 7 => 4.60, 8 => 3.20, 10 => 3.90, 12 => 4.20,
            14 => 3.50, 15 => 3.80, 18 => 4.40, 20 => 3.20, 22 => 3.60, 24 => 4.10, 26 => 3.80, 28 => 3.50,
        ];
        foreach ($coffee as $day => $amount) {
            $entries[] = [$day, 'expense', 'Food & Dining', 'Corner Coffee', 'Cash', $amount, $day % 3 === 0 ? 'Flat white' : 'Coffee'];
        }

        $groceries = [42.15, 18.60, 67.30, 23.85, 54.10, 31.40, 88.25, 26.70, 47.95, 35.20];
        foreach ($groceries as $i => $amount) {
            $entries[] = $i % 3 === 2
                ? [$i * 3 + 2, 'expense', 'Food & Dining', 'Green Basket Market', 'Cash', $amount, 'Fruit and vegetables']
                : [$i * 3 + 2, 'expense', 'Food & Dining', 'FreshMart', 'Main Checking', $amount, 'Groceries'];
        }

        $transport = [
            [2, 'City Metro', 'Main Checking', 2.90, 'Metro ticket'],
            [4, 'City Metro', 'Main Checking', 2.90, 'Metro ticket'],
            [6, 'Bolt', 'Main Checking', 14.60, 'Ride home'],
            [8, 'City Metro', 'Main Checking', 2.90, 'Metro ticket'],
            [10, 'City Metro', 'Main Checking', 49.00, 'Monthly metro pass'],
            [13, 'Bolt', 'Main Checking', 22.30, 'Ride to the airport'],
            [17, 'Bolt', 'Main Checking', 11.80, 'Ride home'],
            [20, 'City Metro', 'Cash', 2.90, 'Metro ticket'],
            [24, 'Bolt', 'Main Checking', 16.40, 'Late ride home'],
            [27, 'City Metro', 'Cash', 2.90, 'Metro ticket'],
        ];
        foreach ($transport as [$day, $party, $wallet, $amount, $description]) {
            $entries[] = [$day, 'expense', 'Transportation', $party, $wallet, $amount, $description];
        }

        $dining = [[7, 38.50, 'Dinner with friends'], [15, 21.90, 'Lunch'], [21, 42.00, 'Pizza night'], [25, 17.40, 'Lunch'], [28, 26.80, 'Dinner']];
        foreach ($dining as [$day, $amount, $description]) {
            $entries[] = [$day, 'expense', 'Food & Dining', 'Trattoria Roma', 'Main Checking', $amount, $description];
        }

        return $entries;
    }

    public function run(User $user): void
    {
        $user->setConfigValue(ConfigurationKeys::DEFAULT_CURRENCY, 'EUR', ConfigValueType::String);
        $user->setConfigValue(ConfigurationKeys::DEFAULT_LANG, 'en', ConfigValueType::String);
        $user->setConfigValue(ConfigurationKeys::ONBOARDING_COMPLETE, true, ConfigValueType::Boolean);
        $user->setConfigValue(ConfigurationKeys::MANUAL_EXCHANGE_RATES, ['USD-EUR' => 0.92], ConfigValueType::Json);

        $wallets = $this->wallets($user);
        $categories = $this->categories($user);
        $parties = $this->parties($user);
        $start = Carbon::today()->subDays(29);

        foreach ($this->entries() as [$day, $type, $category, $party, $wallet, $amount, $description]) {
            $datetime = $start->copy()->addDays($day)->setTime(8 + ($day * 5) % 12, ($day * 17) % 60);
            if ($datetime->isFuture()) {
                $datetime = Carbon::now()->subMinutes(30);
            }

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'wallet_id' => $wallets[$wallet]->id,
                'party_id' => $party ? $parties[$party]->id : null,
                'amount' => $amount,
                'type' => $type,
                'description' => $description,
                'datetime' => $datetime,
            ]);

            if ($category !== null) {
                $transaction->categories()->attach($categories[$category]->id);
            }
        }

        app(TransferService::class)->transfer(
            amountToSend: 300,
            fromWallet: $wallets['Main Checking']->fresh(),
            amountToReceive: 326.10,
            toWallet: $wallets['Dollar Savings']->fresh(),
            user: $user,
            exchangeRate: 1.087,
            datetime: $start->copy()->addDays(2)->setTime(19, 30)->toDateTimeString(),
        );
    }

    private function wallets(User $user): array
    {
        $registered = $user->wallets()->where('name', __('Main Wallet'))->first();
        $registered?->update(['name' => 'Main Checking']);

        $wallets = [];
        foreach ($this->wallets as $name => $wallet) {
            $wallets[$name] = Wallet::updateOrCreate(
                ['user_id' => $user->id, 'name' => $name],
                [
                    'type' => $wallet['type'],
                    'currency' => $wallet['currency'],
                    'description' => $wallet['description'],
                    'balance' => $wallet['opening'],
                ],
            );
        }

        return $wallets;
    }

    private function categories(User $user): array
    {
        $categories = [];
        foreach ($this->categories as $category) {
            $categories[$category['name']] = Category::where('user_id', $user->id)
                ->where('name', $category['name'])
                ->where('type', $category['type'])
                ->first() ?? Category::createSeeded($category + ['user_id' => $user->id]);
        }

        return $categories;
    }

    private function parties(User $user): array
    {
        $parties = [];
        foreach ($this->parties as $party) {
            $parties[$party['name']] = Party::updateOrCreate(
                ['user_id' => $user->id, 'name' => $party['name']],
                ['type' => $party['type'], 'description' => $party['description']],
            );
        }

        return $parties;
    }
}
