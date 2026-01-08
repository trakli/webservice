<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'amount' => $this->faker->randomFloat(2, 1, 1000),
            'description' => $this->faker->sentence,
            'datetime' => now(),
            'type' => $this->faker->randomElement(['income', 'expense']),
        ];
    }

    public function withUserAndWallet(?User $user = null): static
    {
        return $this->state(function () use ($user) {
            $user = $user ?? User::factory()->create();
            $wallet = Wallet::factory()->create(['user_id' => $user->id]);

            return [
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
            ];
        });
    }
}
