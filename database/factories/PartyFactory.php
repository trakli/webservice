<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Party>
 */
class PartyFactory extends Factory
{
    private array $incomeParties = [
        'Acme Corporation' => ['description' => 'Primary employer', 'type' => 'business'],
        'TechStart Inc' => ['description' => 'Freelance client', 'type' => 'business'],
        'Digital Solutions Ltd' => ['description' => 'Contract work', 'type' => 'business'],
        'Investment Portfolio' => ['description' => 'Stock dividends', 'type' => 'business'],
        'Family' => ['description' => 'Family members', 'type' => 'individual'],
    ];

    private array $expenseParties = [
        'SuperMart' => ['description' => 'Grocery store', 'type' => 'business'],
        'City Properties' => ['description' => 'Landlord', 'type' => 'business'],
        'Power Company' => ['description' => 'Electricity provider', 'type' => 'business'],
        'Water Works' => ['description' => 'Water utility', 'type' => 'business'],
        'Internet Plus' => ['description' => 'ISP provider', 'type' => 'business'],
        'Shell Station' => ['description' => 'Gas station', 'type' => 'business'],
        'Uber' => ['description' => 'Ride sharing', 'type' => 'business'],
        'Pizza Palace' => ['description' => 'Restaurant', 'type' => 'business'],
        'Café Express' => ['description' => 'Coffee shop', 'type' => 'business'],
        'Netflix' => ['description' => 'Streaming service', 'type' => 'business'],
        'Spotify' => ['description' => 'Music streaming', 'type' => 'business'],
        'City Pharmacy' => ['description' => 'Pharmacy', 'type' => 'business'],
        'Fashion Store' => ['description' => 'Clothing retailer', 'type' => 'business'],
        'Amazon' => ['description' => 'Online shopping', 'type' => 'business'],
        'Udemy' => ['description' => 'Online courses', 'type' => 'business'],
        'State Insurance' => ['description' => 'Insurance provider', 'type' => 'business'],
    ];

    public function definition(): array
    {
        $allParties = array_merge($this->incomeParties, $this->expenseParties);
        $name = $this->faker->randomElement(array_keys($allParties));
        $partyData = $allParties[$name];

        return [
            'name' => $name,
            'description' => $partyData['description'],
            'type' => $partyData['type'],
        ];
    }

    public function income(): static
    {
        return $this->state(function (array $attributes) {
            $name = $this->faker->randomElement(array_keys($this->incomeParties));
            $partyData = $this->incomeParties[$name];

            return [
                'name' => $name,
                'description' => $partyData['description'],
                'type' => $partyData['type'],
            ];
        });
    }

    public function expense(): static
    {
        return $this->state(function (array $attributes) {
            $name = $this->faker->randomElement(array_keys($this->expenseParties));
            $partyData = $this->expenseParties[$name];

            return [
                'name' => $name,
                'description' => $partyData['description'],
                'type' => $partyData['type'],
            ];
        });
    }
}
