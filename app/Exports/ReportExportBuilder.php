<?php

namespace App\Exports;

use Illuminate\Support\Str;

/**
 * Turns a StatsService payload into an ExportDocument. The statement reports
 * only what the payload actually contains, so a request scoped to one section
 * produces a document covering that section rather than empty headings.
 */
class ReportExportBuilder
{
    /**
     * @param  array<string, mixed>  $stats  The return value of StatsService::compute()
     * @param  array<string, scalar|null>  $meta
     */
    public function build(array $stats, string $title, array $meta = []): ExportDocument
    {
        $currency = (string) ($stats['currency'] ?? 'USD');

        $sections = array_values(array_filter([
            $this->overview($stats, $currency),
            $this->position($stats, $currency),
            $this->categories($stats, $currency, 'expenses', __('Top expense categories')),
            $this->categories($stats, $currency, 'income', __('Top income categories')),
            $this->largestTransactions($stats, $currency),
        ]));

        return new ExportDocument(
            title: $title,
            sections: $sections,
            meta: $meta + [__('Currency') => $currency],
            notices: $this->notices($stats),
        );
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array<int, string>
     */
    private function notices(array $stats): array
    {
        if (empty($stats['partial'])) {
            return [];
        }

        // Without this line the reader has no way to tell that some amounts
        // were left out of the totals rather than being zero.
        return [__('Some amounts could not be converted and are excluded from these totals. Affected currencies: :currencies', [
            'currencies' => implode(', ', (array) ($stats['unconverted_currencies'] ?? [])),
        ])];
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function overview(array $stats, string $currency): ?ExportSection
    {
        if (! is_array($stats['overview'] ?? null)) {
            return null;
        }

        $overview = $stats['overview'];

        return new ExportSection(
            heading: __('Overview'),
            summary: [
                __('Total balance') => $this->money($overview['total_balance'] ?? 0, $currency),
                __('Total income') => $this->money($overview['total_income'] ?? 0, $currency),
                __('Total expenses') => $this->money($overview['total_expenses'] ?? 0, $currency),
                __('Net cash flow') => $this->money($overview['net_cash_flow'] ?? 0, $currency),
                __('Average monthly income') => $this->money($overview['avg_monthly_income'] ?? 0, $currency),
                __('Average monthly expenses') => $this->money($overview['avg_monthly_expenses'] ?? 0, $currency),
                __('Savings rate') => number_format((float) ($overview['savings_rate'] ?? 0), 1) . '%',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function position(array $stats, string $currency): ?ExportSection
    {
        if (! is_array($stats['position'] ?? null)) {
            return null;
        }

        $position = $stats['position'];

        return new ExportSection(
            heading: __('Financial position'),
            summary: [
                __('Earned income') => $this->money($position['earned_income'] ?? 0, $currency),
                __('Discretionary spend') => $this->money($position['discretionary_spend'] ?? 0, $currency),
                __('Cash balance') => $this->money($position['cash_balance'] ?? 0, $currency),
                __('Holdings value') => $this->money($position['holdings_value'] ?? 0, $currency),
                __('Total net worth') => $this->money($position['total_net_worth'] ?? 0, $currency),
                __('Loans and debt (net)') => $this->money($position['loans_debt_net'] ?? 0, $currency),
                __('Investment principal') => $this->money($position['investment_principal'] ?? 0, $currency),
                __('Investment returns') => $this->money($position['investment_returns'] ?? 0, $currency),
                __('Gifts received') => $this->money($position['gifts_received'] ?? 0, $currency),
                __('Net worth change') => $this->money($position['net_worth_delta'] ?? 0, $currency),
            ],
            note: __('Borrowed money and investment purchases are balance-neutral and excluded from the net worth change.'),
        );
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function categories(array $stats, string $currency, string $bucket, string $heading): ?ExportSection
    {
        $categories = $stats['top_categories'][$bucket] ?? null;

        if (! is_array($categories) || $categories === []) {
            return null;
        }

        $rows = [];
        foreach ($categories as $category) {
            $rows[] = [
                (string) ($category['name'] ?? __('Uncategorized')),
                $this->money($category['amount'] ?? 0, $currency),
                (int) ($category['transaction_count'] ?? 0),
            ];
        }

        return new ExportSection(
            heading: $heading,
            columns: [__('Category'), __('Amount'), __('Transactions')],
            rows: $rows,
        );
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function largestTransactions(array $stats, string $currency): ?ExportSection
    {
        $largest = $stats['largest_transactions'] ?? null;

        if (! is_array($largest)) {
            return null;
        }

        $rows = [];
        foreach (['income', 'expense'] as $type) {
            if (! is_array($largest[$type] ?? null)) {
                continue;
            }

            $rows[] = [
                Str::ucfirst($type),
                (string) ($largest[$type]['date'] ?? ''),
                (string) ($largest[$type]['description'] ?? ''),
                (string) ($largest[$type]['category'] ?? ''),
                $this->money($largest[$type]['amount'] ?? 0, $currency),
            ];
        }

        if ($rows === []) {
            return null;
        }

        return new ExportSection(
            heading: __('Largest transactions'),
            columns: [__('Type'), __('Date'), __('Description'), __('Category'), __('Amount')],
            rows: $rows,
        );
    }

    private function money(mixed $value, string $currency): string
    {
        return number_format((float) $value, 2) . ' ' . $currency;
    }
}
