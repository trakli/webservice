<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category>
 */
class CategoryFactory extends Factory
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
                'Groceries',
                'Rent',
                'Travel',
                'Shopping',
                'Utilities',
                'Entertainment',
                'Dining',
                'Health',
                'Fitness',
                'Savings',
            ]),
            'description' => $this->faker->sentence,
            'type' => $this->faker->randomElement(['income', 'expense']),
        ];
    }
}
