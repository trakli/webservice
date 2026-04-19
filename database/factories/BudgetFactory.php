<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Budget>
 */
class BudgetFactory extends Factory
{
    protected $model = Budget::class;

    public function definition(): array
    {
        return [
            'owner_type' => User::class,
            'owner_id' => User::factory(),
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'amount' => $this->faker->randomFloat(2, 100, 2000),
            'currency' => 'USD',
            'period_type' => Budget::PERIOD_MONTHLY,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => null,
            'rollover_enabled' => false,
            'threshold_percent' => 80,
            'forecast_alerts_enabled' => true,
            'is_active' => true,
        ];
    }

    public function weekly(): static
    {
        return $this->state(fn () => ['period_type' => Budget::PERIOD_WEEKLY]);
    }

    public function yearly(): static
    {
        return $this->state(fn () => ['period_type' => Budget::PERIOD_YEARLY]);
    }

    public function custom(string $start, string $end): static
    {
        return $this->state(fn () => [
            'period_type' => Budget::PERIOD_CUSTOM,
            'start_date' => $start,
            'end_date' => $end,
        ]);
    }

    public function rollover(): static
    {
        return $this->state(fn () => ['rollover_enabled' => true]);
    }
}
