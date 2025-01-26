<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Party>
 */
class PartyFactory extends Factory
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
                'John Doe',
                'University of Buea',
                'Jane Doe',
                'Bob',
                'University of Cambridge',
                'Harvard University',
                'Alice',
                'Filling Station',
                'Wallibi Park',
                'Disneyland',
            ]),
            'description' => $this->faker->sentence,
        ];
    }
}
