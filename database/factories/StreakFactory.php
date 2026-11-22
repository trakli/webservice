<?php

namespace Database\Factories;

use App\Enums\StreakPeriod;
use App\Enums\StreakType;
use App\Models\Streak;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StreakFactory extends Factory
{
    protected $model = Streak::class;

    public function definition(): array
    {
        return [
            'owner_type' => User::class,
            'owner_id' => User::factory(),
            'type' => StreakType::TRANSACTION,
            'period' => StreakPeriod::DAILY,
            'current_length' => 1,
            'longest_length' => 1,
            'last_notified_length' => 0,
            'started_on' => now()->toDateString(),
            'last_tracked_on' => now()->toDateString(),
        ];
    }
}
