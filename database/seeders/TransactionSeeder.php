<?php

namespace Database\Seeders;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;

class TransactionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (range(1, 10) as $index) {
            $user = User::find($index);
            if (! $user) {
                continue;
            }

            $categories = $user->categories;
            $parties = $user->parties;
            $wallets = $user->wallets;

            if ($categories->isEmpty() || $parties->isEmpty() || $wallets->isEmpty()) {
                continue;
            }

            foreach (range(1, 5) as $_) {
                $category = $categories->random();

                $transaction = Transaction::create([
                    'user_id' => $user->id,
                    'party_id' => $parties->random()->id,
                    'wallet_id' => $wallets->random()->id,
                    'amount' => rand(1, 100) * 100,
                    'type' => ['income', 'expense'][rand(0, 1)],
                    'description' => ucfirst(fake()->words(3, true)),
                    'datetime' => now(),
                ]);

                $transaction->categories()->attach($category->id);
            }
        }
    }
}
