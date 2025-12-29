<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Party>
 */
class PartyFactory extends Factory
{
    private array $incomeParties = [
        'Acme Corporation' => 'Primary employer',
        'TechStart Inc' => 'Freelance client',
        'Digital Solutions Ltd' => 'Contract work',
        'Investment Portfolio' => 'Stock dividends',
        'Family' => 'Family members',
    ];

    private array $expenseParties = [
        'SuperMart' => 'Grocery store',
        'City Properties' => 'Landlord',
        'Power Company' => 'Electricity provider',
        'Water Works' => 'Water utility',
        'Internet Plus' => 'ISP provider',
        'Shell Station' => 'Gas station',
        'Uber' => 'Ride sharing',
        'Pizza Palace' => 'Restaurant',
        'Café Express' => 'Coffee shop',
        'Netflix' => 'Streaming service',
        'Spotify' => 'Music streaming',
        'City Pharmacy' => 'Pharmacy',
        'Fashion Store' => 'Clothing retailer',
        'Amazon' => 'Online shopping',
        'Udemy' => 'Online courses',
        'State Insurance' => 'Insurance provider',
    ];

    public function definition(): array
    {
        $type = $this->faker->randomElement(['income', 'expense']);
        $parties = $type === 'income' ? $this->incomeParties : $this->expenseParties;
        $name = $this->faker->randomElement(array_keys($parties));

        return [
            'name' => $name,
            'description' => $parties[$name],
            'type' => $type,
        ];
    }

    public function income(): static
    {
        return $this->state(function (array $attributes) {
            $name = $this->faker->randomElement(array_keys($this->incomeParties));

            return [
                'name' => $name,
                'description' => $this->incomeParties[$name],
                'type' => 'income',
            ];
        });
    }

    public function expense(): static
    {
        return $this->state(function (array $attributes) {
            $name = $this->faker->randomElement(array_keys($this->expenseParties));

            return [
                'name' => $name,
                'description' => $this->expenseParties[$name],
                'type' => 'expense',
            ];
        });
    }
}
