<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transfer>
 */
class TransferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => auth()->id() ?? User::factory(),
            'amount' => 100,
            'from_wallet_id' => fn (array $attributes) => Wallet::factory()->create([
                'user_id' => $attributes['user_id'], 'balance' => 1000,
            ])->id,
            'to_wallet_id' => fn (array $attributes) => Wallet::factory()->create([
                'user_id' => $attributes['user_id'],
            ])->id,
        ];
    }
}
