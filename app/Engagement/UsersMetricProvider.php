<?php

namespace App\Engagement;

use App\Engagement\Concerns\SeriesFromDates;
use App\Models\User;
use Carbon\CarbonImmutable;
use Whilesmart\Engagement\Contracts\MetricProvider;
use Whilesmart\Engagement\Support\Metric;
use Whilesmart\Engagement\Support\Period;

class UsersMetricProvider implements MetricProvider
{
    use SeriesFromDates;

    public function key(): string
    {
        return 'users';
    }

    public function label(): string
    {
        return 'Users';
    }

    public function metrics(Period $period): array
    {
        $signups = User::query()->whereBetween('created_at', [$period->start, $period->end])->pluck('created_at');
        $today = User::query()->where('created_at', '>=', CarbonImmutable::now()->startOfDay())->count();
        $thisMonth = User::query()->where('created_at', '>=', CarbonImmutable::now()->startOfMonth())->count();

        return [
            Metric::count('total_users', 'Total users', User::query()->count()),
            Metric::count('registrations_today', 'Registered today', $today),
            Metric::count('registrations_month', 'Registered this month', $thisMonth),
            Metric::count('new_users', 'New users', $signups->count()),
            Metric::series('new_users_series', 'New users over time', $this->seriesRows($signups, $period)),
        ];
    }
}
