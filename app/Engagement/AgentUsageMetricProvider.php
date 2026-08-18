<?php

namespace App\Engagement;

use App\Models\User;
use Carbon\CarbonImmutable;
use Whilesmart\AgentMetrics\Models\TokenUsage;
use Whilesmart\Engagement\Contracts\MetricProvider;
use Whilesmart\Engagement\Support\Metric;
use Whilesmart\Engagement\Support\Period;

class AgentUsageMetricProvider implements MetricProvider
{
    public function key(): string
    {
        return 'agent_usage';
    }

    public function label(): string
    {
        return 'AI usage';
    }

    public function metrics(Period $period): array
    {
        $usage = TokenUsage::query()
            ->whereBetween('created_at', [$period->start, $period->end])
            ->get(['owner_type', 'owner_id', 'total_tokens', 'created_at']);

        return [
            Metric::sum('ai_tokens_used', 'AI tokens used', (float) $usage->sum('total_tokens'), 'tokens'),
            Metric::series('ai_tokens_series', 'AI tokens over time', $this->series($usage, $period), 'tokens'),
            Metric::ranking('ai_tokens_by_user', 'AI tokens by user', $this->ranking($usage)),
        ];
    }

    private function series(iterable $usage, Period $period): array
    {
        $format = $period->bucketFormat();
        $totals = [];

        foreach ($usage as $row) {
            $bucket = CarbonImmutable::parse($row->created_at)->format($format);
            $totals[$bucket] = ($totals[$bucket] ?? 0) + $row->total_tokens;
        }

        return collect($period->buckets())
            ->map(fn ($bucket) => [
                'date' => $bucket->format($format),
                'value' => $totals[$bucket->format($format)] ?? 0,
            ])
            ->all();
    }

    private function ranking(iterable $usage): array
    {
        $userType = (new User())->getMorphClass();
        $totals = collect($usage)
            ->where('owner_type', $userType)
            ->groupBy('owner_id')
            ->map(fn ($rows) => (int) $rows->sum('total_tokens'))
            ->sortDesc()
            ->take(8);
        $users = User::query()->whereIn('id', $totals->keys())->get()->keyBy('id');

        return $totals->map(function (int $tokens, int|string $userId) use ($users) {
            $user = $users->get($userId);
            $name = $user
                ? trim(sprintf(
                    '%s %s',
                    (string) $user->getAttribute('first_name'),
                    (string) $user->getAttribute('last_name')
                ))
                : '';

            return [
                'label' => $user ? ($name ?: $user->getAttribute('email')) : "User {$userId}",
                'value' => $tokens,
            ];
        })->values()->all();
    }
}
