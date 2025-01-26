<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Wallet>
 */
class WalletFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement([
                'Ecobank',
                'Mobile Money',
                'Orange Money',
                'United Bank for Africa',
                'My Cash',
                'Wells Fargo',
                'Business Cash',
                'US Bank',
            ]),
            'description' => $this->faker->sentence,
            'type' => $this->faker->randomElement(['bank', 'cash', 'credit_card', 'mobile']),
        ];
    }
}
