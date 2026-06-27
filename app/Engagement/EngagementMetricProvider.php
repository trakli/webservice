<?php

namespace App\Engagement;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Whilesmart\Engagement\Contracts\MetricProvider;
use Whilesmart\Engagement\Support\Metric;
use Whilesmart\Engagement\Support\Period;

class EngagementMetricProvider implements MetricProvider
{
    public function key(): string
    {
        return 'engagement';
    }

    public function label(): string
    {
        return 'Engagement';
    }

    public function metrics(Period $period): array
    {
        $totalUsers = User::query()->count();
        $periodTx = Transaction::query()->whereBetween('datetime', [$period->start, $period->end])->count();
        $avgPerUser = $totalUsers > 0 ? round($periodTx / $totalUsers, 1) : 0;

        return [
            Metric::sum('avg_transactions_per_user', 'Avg transactions / user', $avgPerUser),
            Metric::ranking('most_active_users', 'Most active users', $this->mostActiveUsers($period)),
            Metric::ranking('most_used_features', 'Most used features', $this->featureUsage()),
        ];
    }

    /**
     * @return array<int, array{label: string, value: int}>
     */
    private function mostActiveUsers(Period $period): array
    {
        return User::query()
            ->withCount(['transactions' => fn ($q) => $q->whereBetween('datetime', [$period->start, $period->end])])
            ->orderByDesc('transactions_count')
            ->limit(8)
            ->get()
            ->filter(fn ($u) => $u->transactions_count > 0)
            ->map(fn ($u) => [
                'label' => trim("{$u->first_name} {$u->last_name}") ?: $u->email,
                'value' => $u->transactions_count,
            ])
            ->values()
            ->all();
    }

    /**
     * Proxy "feature usage" from the volume of records each feature produces,
     * until per-event engagement is recorded through the package.
     *
     * @return array<int, array{label: string, value: int}>
     */
    private function featureUsage(): array
    {
        $features = [
            'Transactions' => 'transactions',
            'Wallets' => 'wallets',
            'Categories' => 'categories',
            'Parties' => 'parties',
            'Budgets' => 'budgets',
            'Holdings' => 'holdings',
        ];

        $rows = [];
        foreach ($features as $label => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $count = (int) DB::table($table)->count();
            if ($count > 0) {
                $rows[] = ['label' => $label, 'value' => $count];
            }
        }

        usort($rows, fn ($rowA, $rowB) => $rowB['value'] <=> $rowA['value']);

        return $rows;
    }
}
