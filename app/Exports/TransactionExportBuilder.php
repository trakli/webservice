<?php

namespace App\Exports;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Turns a filtered transaction query into an ExportDocument. Every format is
 * rendered from the result, so the columns cannot drift between CSV, XLSX
 * and PDF.
 */
class TransactionExportBuilder
{
    /**
     * Beyond this the export stops being something a person reads and starts
     * being a database dump; refusing is clearer than timing out mid-download.
     */
    public const MAX_ROWS = 20000;

    /**
     * @param  array<string, scalar|null>  $meta
     */
    public function build(Builder|Relation $query, string $title, array $meta = []): ExportDocument
    {
        $query = $query->with(['wallet', 'party', 'categories']);

        $totals = [
            'income' => (float) (clone $query)->where('type', 'income')->sum('amount'),
            'expenses' => (float) (clone $query)->where('type', 'expense')->sum('amount'),
        ];

        $section = new ExportSection(
            heading: __('Transactions'),
            columns: [
                __('Date'),
                __('Type'),
                __('Intent'),
                __('Amount'),
                __('Currency'),
                __('Wallet'),
                __('Category'),
                __('Party'),
                __('Description'),
                __('Transfer leg'),
            ],
            rows: $this->rows($query),
            summary: [
                __('Total income') => $this->amount($totals['income']),
                __('Total expenses') => $this->amount($totals['expenses']),
                __('Net') => $this->amount($totals['income'] - $totals['expenses']),
            ],
            note: __('Amounts are shown in each transaction\'s own wallet currency and are not converted.'),
        );

        return new ExportDocument(
            title: $title,
            sections: [$section],
            meta: $meta,
        );
    }

    public function countRows(Builder|Relation $query): int
    {
        return (clone $query)->count();
    }

    /**
     * Streamed so a large export does not hold every model in memory at once.
     *
     * @return iterable<int, array<int, scalar|null>>
     */
    private function rows(Builder|Relation $query): iterable
    {
        foreach ($query->lazy() as $transaction) {
            yield $this->row($transaction);
        }
    }

    /**
     * @return array<int, scalar|null>
     */
    private function row(Transaction $transaction): array
    {
        return [
            $transaction->datetime ? Carbon::parse($transaction->datetime)->toDateTimeString() : null,
            $transaction->type,
            str_replace('_', ' ', (string) $transaction->intent),
            (float) $transaction->amount,
            $transaction->wallet?->currency,
            $transaction->wallet?->name,
            $transaction->categories->pluck('name')->implode(', '),
            $transaction->party?->name,
            $transaction->description,
            // A transfer writes an expense and an income leg. Flagging them
            // keeps a reader from adding both into a total that double-counts.
            $transaction->transfer_id ? __('yes') : '',
        ];
    }

    private function amount(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
