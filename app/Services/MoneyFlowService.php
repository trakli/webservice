<?php

namespace App\Services;

use App\Enums\TransactionIntent;
use App\Models\Transaction;
use App\Models\Transfer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MoneyFlowService
{
    public function __construct(private ExchangeRateService $rates)
    {
    }

    public function compute($owner, Carbon $from, Carbon $to, array $walletIds, string $currency): array
    {
        $category = DB::table('categorizables')->where('categorizable_type', Transaction::class)
            ->select('categorizable_id')->selectRaw('MIN(category_id) AS category_id')->groupBy('categorizable_id');
        $dimensions = ['transactions.wallet_id', 'wallets.name', 'wallets.currency', 'transactions.party_id', 'parties.name', 'transactions.intent', 'transactions.type', 'primary_category.category_id', 'categories.name'];
        $query = Transaction::ownedBy($owner)->nonTransfer()
            ->join('wallets', 'wallets.id', '=', 'transactions.wallet_id')
            ->leftJoin('parties', 'parties.id', '=', 'transactions.party_id')
            ->leftJoinSub($category, 'primary_category', fn ($join) => $join->on('primary_category.categorizable_id', '=', 'transactions.id'))
            ->leftJoin('categories', 'categories.id', '=', 'primary_category.category_id')
            ->whereBetween('transactions.datetime', [$from, $to])
            ->when($walletIds, fn ($q) => $q->whereIn('transactions.wallet_id', $walletIds))
            ->selectRaw('transactions.wallet_id, wallets.name AS wallet_name, wallets.currency, transactions.party_id, parties.name AS party_name, transactions.intent, transactions.type, primary_category.category_id, categories.name AS category_name')
            ->selectRaw('SUM(transactions.amount) AS amount, COUNT(*) AS movement_count')
            ->groupBy($dimensions);
        $partial = false;
        $rateCache = [];
        $convert = function ($amount, $source) use ($owner, $currency, &$partial, &$rateCache) {
            if (! array_key_exists($source, $rateCache)) {
                $rateCache[$source] = $source === $currency ? 1.0 : $this->rates->getRate($source, $currency, $owner instanceof \App\Models\User ? $owner : null);
            }
            if ($rateCache[$source] === null) {
                $partial = true;

                return 0.0;
            }

            return (float) bcmul((string) $amount, (string) $rateCache[$source], 8);
        };
        $rows = $query->toBase()->get();
        $categoryTotals = [];
        foreach ($rows as $row) {
            $row->converted = $convert($row->amount, $row->currency);
            if ($row->type === 'expense' && in_array($row->intent, ['regular', 'fee'], true)) {
                $key = $row->category_id ?? 'other';
                $categoryTotals[$key] = ($categoryTotals[$key] ?? 0) + $row->converted;
            }
        }
        unset($categoryTotals['other']);
        arsort($categoryTotals);
        $topCategories = array_slice(array_keys($categoryTotals), 0, 8);
        $nodes = [];
        $edges = [];
        $node = function (string $id, string $lane, string $name, string $kind) use (&$nodes): string {
            $nodes[$id] ??= ['id' => $id, 'lane' => $lane, 'name' => $name, 'kind' => $kind, 'total' => 0.0];

            return $id;
        };
        $edge = function (string $source, string $target, string $type, float $amount, int $count, ?bool $pending = null) use (&$edges, &$nodes) {
            $id = $type.':'.$source.':'.$target.($pending === null ? '' : ($pending ? ':pending' : ':settled'));
            $edges[$id] ??= ['id' => $id, 'source' => $source, 'target' => $target, 'type' => $type, 'amount' => 0.0, 'count' => 0];
            $edges[$id]['amount'] += $amount;
            $edges[$id]['count'] += $count;
            if ($pending !== null) {
                $edges[$id]['pending'] = $pending;
            }
            $nodes[$source]['total'] += $amount;
            $nodes[$target]['total'] += $amount;
        };
        $pendingParties = Transaction::ownedBy($owner)->whereIn('intent', ['loan_given', 'loan_received', 'debt_owed'])
            ->whereNotNull('party_id')
            ->whereRaw('COALESCE(transactions.owed_amount, transactions.amount) > (SELECT COALESCE(SUM(amount_applied), 0) FROM debt_settlements WHERE debt_transaction_id = transactions.id)')
            ->pluck('party_id')->flip()->all();
        $debtParties = $rows->filter(fn ($row) => $row->party_id && TransactionIntent::tryFrom($row->intent)?->isLoanOrDebt())
            ->pluck('party_id')->flip()->all();
        foreach ($rows as $row) {
            $intent = TransactionIntent::tryFrom($row->intent);
            $isLoan = $intent?->isLoanOrDebt();
            $isSpend = $row->type === 'expense' && in_array($row->intent, ['regular', 'fee'], true);
            $isIncome = $row->type === 'income' && ! $isLoan;
            if (! $isLoan && ! $isSpend && ! $isIncome) {
                continue;
            }
            $wallet = $node('wallet:'.$row->wallet_id, 'wallet', $row->wallet_name, 'wallet');
            if ($isLoan) {
                $person = $node($row->party_id ? 'person:party:'.$row->party_id : 'person:unknown', 'person', $row->party_name ?? 'Unknown', 'party');
                $out = $row->type === 'expense';
                $edge($out ? $wallet : $person, $out ? $person : $wallet, 'loan', $row->converted, (int) $row->movement_count, isset($pendingParties[$row->party_id]));
            } elseif ($isSpend) {
                $top = in_array($row->category_id, $topCategories);
                $target = $node('category:'.($top ? $row->category_id : 'other'), 'category', $top ? $row->category_name : 'Other', 'category');
                $edge($wallet, $target, 'spend', $row->converted, (int) $row->movement_count);
            } else {
                $source = isset($debtParties[$row->party_id])
                    ? $node('person:party:'.$row->party_id, 'person', $row->party_name, 'party')
                    : $node($row->party_id ? 'source:party:'.$row->party_id : 'source:unknown', 'source', $row->party_name ?? 'Unknown', 'party');
                $edge($source, $wallet, 'income', $row->converted, (int) $row->movement_count);
            }
        }
        $transfers = Transfer::ownedBy($owner)
            ->join('wallets AS source_wallet', 'source_wallet.id', '=', 'transfers.from_wallet_id')
            ->join('wallets AS target_wallet', 'target_wallet.id', '=', 'transfers.to_wallet_id')
            ->whereBetween('transfers.datetime', [$from, $to])
            ->when($walletIds, fn ($q) => $q->where(fn ($q) => $q->whereIn('transfers.from_wallet_id', $walletIds)->orWhereIn('transfers.to_wallet_id', $walletIds)))
            ->selectRaw('transfers.from_wallet_id, transfers.to_wallet_id, source_wallet.name AS source_name, target_wallet.name AS target_name, source_wallet.currency, SUM(transfers.amount) AS amount, COUNT(*) AS movement_count')
            ->groupBy('transfers.from_wallet_id', 'transfers.to_wallet_id', 'source_wallet.name', 'target_wallet.name', 'source_wallet.currency')->toBase()->get();
        foreach ($transfers as $row) {
            $source = $node('wallet:'.$row->from_wallet_id, 'wallet', $row->source_name, 'wallet');
            $target = $node('wallet:'.$row->to_wallet_id, 'wallet', $row->target_name, 'wallet');
            $edge($source, $target, 'transfer', $convert($row->amount, $row->currency), (int) $row->movement_count);
        }

        return ['currency' => $currency, 'partial' => $partial, 'nodes' => array_values($nodes), 'edges' => array_values($edges)];
    }
}
