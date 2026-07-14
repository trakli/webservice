<?php

namespace App\Engagement;

use App\Models\Wallet;
use Whilesmart\Engagement\Contracts\MetricProvider;
use Whilesmart\Engagement\Support\Metric;
use Whilesmart\Engagement\Support\Period;

class AccountsMetricProvider implements MetricProvider
{
    public function key(): string
    {
        return 'accounts';
    }

    public function label(): string
    {
        return 'Accounts';
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function metrics(Period $period): array
    {
        return [
            Metric::count('total_wallets', 'Wallets', Wallet::query()->count()),
        ];
    }
}
