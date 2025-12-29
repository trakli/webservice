<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Group;
use App\Models\Party;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    private array $incomeCategories = [
        ['name' => 'Salary', 'description' => 'Monthly salary and wages'],
        ['name' => 'Freelance', 'description' => 'Freelance and contract work'],
        ['name' => 'Investments', 'description' => 'Investment returns and dividends'],
        ['name' => 'Gifts', 'description' => 'Money received as gifts'],
        ['name' => 'Refunds', 'description' => 'Refunds and reimbursements'],
    ];

    private array $expenseCategories = [
        ['name' => 'Groceries', 'description' => 'Food and household supplies'],
        ['name' => 'Rent', 'description' => 'Monthly rent payments'],
        ['name' => 'Utilities', 'description' => 'Electricity, water, internet'],
        ['name' => 'Transport', 'description' => 'Fuel, public transport, rides'],
        ['name' => 'Dining', 'description' => 'Restaurants and takeout'],
        ['name' => 'Entertainment', 'description' => 'Movies, games, subscriptions'],
        ['name' => 'Health', 'description' => 'Medical and pharmacy'],
        ['name' => 'Shopping', 'description' => 'Clothing and personal items'],
        ['name' => 'Education', 'description' => 'Courses and learning materials'],
        ['name' => 'Insurance', 'description' => 'Health, car, life insurance'],
    ];

    private array $incomeParties = [
        ['name' => 'Acme Corporation', 'description' => 'Primary employer'],
        ['name' => 'TechStart Inc', 'description' => 'Freelance client'],
        ['name' => 'Digital Solutions Ltd', 'description' => 'Contract work'],
        ['name' => 'Investment Portfolio', 'description' => 'Stock dividends'],
        ['name' => 'Family', 'description' => 'Family members'],
    ];

    private array $expenseParties = [
        ['name' => 'SuperMart', 'description' => 'Grocery store'],
        ['name' => 'City Properties', 'description' => 'Landlord'],
        ['name' => 'Power Company', 'description' => 'Electricity provider'],
        ['name' => 'Water Works', 'description' => 'Water utility'],
        ['name' => 'Internet Plus', 'description' => 'ISP provider'],
        ['name' => 'Shell Station', 'description' => 'Gas station'],
        ['name' => 'Uber', 'description' => 'Ride sharing'],
        ['name' => 'Pizza Palace', 'description' => 'Restaurant'],
        ['name' => 'Café Express', 'description' => 'Coffee shop'],
        ['name' => 'Netflix', 'description' => 'Streaming service'],
        ['name' => 'Spotify', 'description' => 'Music streaming'],
        ['name' => 'City Pharmacy', 'description' => 'Pharmacy'],
        ['name' => 'Fashion Store', 'description' => 'Clothing retailer'],
        ['name' => 'Amazon', 'description' => 'Online shopping'],
        ['name' => 'Udemy', 'description' => 'Online courses'],
        ['name' => 'State Insurance', 'description' => 'Insurance provider'],
    ];

    private array $wallets = [
        ['name' => 'Main Checking', 'description' => 'Primary bank account', 'type' => 'bank'],
        ['name' => 'Savings Account', 'description' => 'Savings for emergencies', 'type' => 'bank'],
        ['name' => 'Cash Wallet', 'description' => 'Physical cash on hand', 'type' => 'cash'],
        ['name' => 'Mobile Money', 'description' => 'Mobile payment account', 'type' => 'mobile'],
        ['name' => 'Credit Card', 'description' => 'Primary credit card', 'type' => 'credit_card'],
    ];

    private array $groups = [
        ['name' => 'Personal', 'description' => 'Personal finances'],
        ['name' => 'Business', 'description' => 'Business expenses'],
        ['name' => 'Family', 'description' => 'Family shared expenses'],
    ];

    public function run(): void
    {
        foreach (range(1, 10) as $index) {
            $user = User::factory()->create([
                'email' => "user{$index}@trakli.app",
                'username' => "user{$index}",
            ]);

            $this->createCategoriesForUser($user);
            $this->createPartiesForUser($user);
            $this->createWalletsForUser($user);
            $this->createGroupsForUser($user);
        }
    }

    private function createCategoriesForUser(User $user): void
    {
        foreach ($this->incomeCategories as $category) {
            Category::create([
                'user_id' => $user->id,
                'name' => $category['name'],
                'description' => $category['description'],
                'type' => 'income',
            ]);
        }

        foreach ($this->expenseCategories as $category) {
            Category::create([
                'user_id' => $user->id,
                'name' => $category['name'],
                'description' => $category['description'],
                'type' => 'expense',
            ]);
        }
    }

    private function createPartiesForUser(User $user): void
    {
        foreach ($this->incomeParties as $party) {
            Party::create([
                'user_id' => $user->id,
                'name' => $party['name'],
                'description' => $party['description'],
                'type' => 'income',
            ]);
        }

        foreach ($this->expenseParties as $party) {
            Party::create([
                'user_id' => $user->id,
                'name' => $party['name'],
                'description' => $party['description'],
                'type' => 'expense',
            ]);
        }
    }

    private function createWalletsForUser(User $user): void
    {
        foreach ($this->wallets as $wallet) {
            Wallet::create([
                'user_id' => $user->id,
                'name' => $wallet['name'],
                'description' => $wallet['description'],
                'type' => $wallet['type'],
            ]);
        }
    }

    private function createGroupsForUser(User $user): void
    {
        foreach ($this->groups as $group) {
            Group::create([
                'user_id' => $user->id,
                'name' => $group['name'],
                'description' => $group['description'],
            ]);
        }
    }
}
