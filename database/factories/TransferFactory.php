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
        $user = auth()->user() ?? User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id]);

        return [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
        ];
    }
}
