<?php

namespace App\Engagement;

use App\Support\ConfigurationKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Whilesmart\Engagement\Contracts\MetricProvider;
use Whilesmart\Engagement\Support\Metric;
use Whilesmart\Engagement\Support\Period;

class DemographicsMetricProvider implements MetricProvider
{
    public function key(): string
    {
        return 'demographics';
    }

    public function label(): string
    {
        return 'Demographics';
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function metrics(Period $period): array
    {
        return [
            Metric::ranking('users_by_country', 'Users by country', $this->distribution(ConfigurationKeys::DEFAULT_COUNTRY)),
            Metric::ranking('users_by_language', 'Users by language', $this->distribution(ConfigurationKeys::DEFAULT_LANG)),
            Metric::ranking('users_by_currency', 'Users by currency', $this->distribution(ConfigurationKeys::DEFAULT_CURRENCY)),
        ];
    }

    /**
     * Count users per stored value of a configuration key.
     *
     * @return array<int, array{label: string, value: int}>
     */
    private function distribution(string $configKey): array
    {
        if (! Schema::hasTable('configurations')) {
            return [];
        }

        return DB::table('configurations')
            ->where('key', $configKey)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->select('value', DB::raw('COUNT(*) as total'))
            ->groupBy('value')
            ->orderByDesc('total')
            ->limit(12)
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->value, 'value' => (int) $row->total])
            ->all();
    }
}
