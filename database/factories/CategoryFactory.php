<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category>
 */
class CategoryFactory extends Factory
{
    private array $incomeCategories = [
        'Salary' => 'Monthly salary and wages',
        'Freelance' => 'Freelance and contract work',
        'Investments' => 'Investment returns and dividends',
        'Gifts' => 'Money received as gifts',
        'Refunds' => 'Refunds and reimbursements',
    ];

    private array $expenseCategories = [
        'Groceries' => 'Food and household supplies',
        'Rent' => 'Monthly rent payments',
        'Utilities' => 'Electricity, water, internet',
        'Transport' => 'Fuel, public transport, rides',
        'Dining' => 'Restaurants and takeout',
        'Entertainment' => 'Movies, games, subscriptions',
        'Health' => 'Medical and pharmacy',
        'Shopping' => 'Clothing and personal items',
        'Education' => 'Courses and learning materials',
        'Insurance' => 'Health, car, life insurance',
    ];

    public function definition(): array
    {
        $type = $this->faker->randomElement(['income', 'expense']);
        $categories = $type === 'income' ? $this->incomeCategories : $this->expenseCategories;
        $name = $this->faker->randomElement(array_keys($categories));

        return [
            'name' => $name,
            'description' => $categories[$name],
            'type' => $type,
        ];
    }

    public function income(): static
    {
        return $this->state(function (array $attributes) {
            $name = $this->faker->randomElement(array_keys($this->incomeCategories));

            return [
                'name' => $name,
                'description' => $this->incomeCategories[$name],
                'type' => 'income',
            ];
        });
    }

    public function expense(): static
    {
        return $this->state(function (array $attributes) {
            $name = $this->faker->randomElement(array_keys($this->expenseCategories));

            return [
                'name' => $name,
                'description' => $this->expenseCategories[$name],
                'type' => 'expense',
            ];
        });
    }
}
