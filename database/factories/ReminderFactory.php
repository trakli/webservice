<?php

namespace Database\Factories;

use App\Enums\ReminderStatus;
use App\Enums\ReminderType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReminderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->sentence(),
            'type' => $this->faker->randomElement(ReminderType::cases()),
            'trigger_at' => now()->addDay(),
            'timezone' => 'UTC',
            'status' => ReminderStatus::ACTIVE,
            'priority' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReminderStatus::ACTIVE,
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReminderStatus::PAUSED,
        ]);
    }

    public function recurring(): static
    {
        return $this->state(fn (array $attributes) => [
            'repeat_rule' => 'FREQ=DAILY;BYHOUR=20;BYMINUTE=0',
        ]);
    }
}
