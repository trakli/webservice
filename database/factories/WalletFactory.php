<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Wallet>
 */
class WalletFactory extends Factory
{
    private array $wallets = [
        ['name' => 'Main Wallet', 'description' => 'Your primary wallet', 'type' => 'cash'],
        ['name' => 'Main Checking', 'description' => 'Primary bank account', 'type' => 'bank'],
        ['name' => 'Savings Account', 'description' => 'Savings for emergencies', 'type' => 'bank'],
        ['name' => 'Mobile Money', 'description' => 'Mobile payment account', 'type' => 'mobile'],
        ['name' => 'Credit Card', 'description' => 'Primary credit card', 'type' => 'credit_card'],
        ['name' => 'Business Account', 'description' => 'Business banking', 'type' => 'bank'],
        ['name' => 'Investment Account', 'description' => 'Investment portfolio', 'type' => 'bank'],
    ];

    public function definition(): array
    {
        $wallet = $this->faker->randomElement($this->wallets);

        return [
            'name' => $wallet['name'],
            'description' => $wallet['description'],
            'type' => $wallet['type'],
        ];
    }

    public function bank(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $this->faker->randomElement(['Main Checking', 'Savings Account', 'Business Account']),
            'type' => 'bank',
        ]);
    }

    public function cash(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Main Wallet',
            'description' => 'Your primary wallet',
            'type' => 'cash',
        ]);
    }

    public function mobile(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Mobile Money',
            'description' => 'Mobile payment account',
            'type' => 'mobile',
        ]);
    }

    public function creditCard(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Credit Card',
            'description' => 'Primary credit card',
            'type' => 'credit_card',
        ]);
    }
}
