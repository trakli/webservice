<?php

namespace App\Services;

use App\Enums\TransactionIntent;
use App\Models\Transaction;
use App\Models\Party;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DebtService
{
    public function validate(Transaction $transaction): void
    {
        $intent = TransactionIntent::tryFrom($transaction->intent ?? 'regular');
        if ($intent?->isLoanOrDebt() && ! $transaction->party_id) {
            throw new HttpException(422, 'A party is required for lending, borrowing and paying back.');
        }
        if ($intent?->isLoanOrDebt()) {
            $expected = $intent->sign() < 0 ? 'expense' : 'income';
            if ($transaction->type !== $expected) {
                throw new HttpException(422, "This debt intent requires an {$expected} transaction.");
            }
        }
        if ($transaction->owed_amount !== null && (! $intent?->isOpening()
            || bccomp((string) $transaction->owed_amount, '0', 4) <= 0
            || bccomp((string) $transaction->owed_amount, (string) $transaction->amount, 4) > 0)) {
            throw new HttpException(422, 'The owed amount must be positive, no greater than the amount, and only set on an opening debt.');
        }
    }

    public function validateExisting(Transaction $transaction): void
    {
        if (! $transaction->exists) {
            return;
        }
        $links = DB::table('debt_settlements')->where('debt_transaction_id', $transaction->id)->where('is_explicit', true)->get();
        $total = '0';
        foreach ($links as $link) {
            $payment = Transaction::find($link->payment_transaction_id);
            $intent = TransactionIntent::tryFrom($payment->intent);
            if (! TransactionIntent::tryFrom($transaction->intent)?->isOpening()
                || ! in_array($transaction->intent, $intent?->openingIntents() ?? [], true)
                || $transaction->party_id !== $payment->party_id
                || $transaction->isDirty('wallet_id')) {
                throw new HttpException(422, 'This edit conflicts with explicit settlements. Edit the paying-back transaction first.');
            }
            $total = bcadd($total, (string) $link->amount_applied, 4);
        }
        if (bccomp($total, (string) ($transaction->owed_amount ?? $transaction->amount), 4) > 0) {
            throw new HttpException(422, 'The owed amount cannot be smaller than its explicit settlements.');
        }
    }

    public function recompute(Transaction $transaction): void
    {
        if (! $transaction->party_id) {
            return;
        }
        DB::transaction(function () use ($transaction) {
            Party::whereKey($transaction->party_id)->lockForUpdate()->first();
            $query = Transaction::where('owner_type', $transaction->owner_type)
                ->where('owner_id', $transaction->owner_id)->where('party_id', $transaction->party_id);
            DB::table('debt_settlements')->whereIn('payment_transaction_id', (clone $query)->select('id'))
                ->where('is_explicit', false)->delete();
            foreach ((clone $query)->whereIn('intent', ['loan_collected', 'loan_repayment', 'debt_settled'])
                ->with('wallet')->orderBy('datetime')->orderBy('id')->lazy(200) as $payment) {
                $intent = TransactionIntent::from($payment->intent);
                if ($payment->debt_settlements_explicit) {
                    continue;
                }
                $candidates = (clone $query)->whereIn('intent', $intent->openingIntents())
                    ->whereRaw('COALESCE(owed_amount, amount) > (SELECT COALESCE(SUM(amount_applied), 0) FROM debt_settlements WHERE debt_transaction_id = transactions.id)')
                    ->with('wallet');
                $candidateCount = (clone $candidates)->count();
                $hasSame = (clone $candidates)->whereHas('wallet', fn ($q) => $q->where('currency', $payment->wallet->currency))->exists();
                $left = (string) $payment->amount;
                foreach ($this->oldest($candidates) as $debt) {
                    if (bccomp($left, '0', 4) <= 0) {
                        break;
                    }
                    $remaining = $this->remaining($debt);
                    $paidRate = $debt->wallet->currency !== $payment->wallet->currency && ! $hasSame && $candidateCount === 1;
                    $rate = $debt->wallet->currency === $payment->wallet->currency ? 1.0
                        : ($paidRate ? bcdiv((string) $payment->amount, $remaining, 12)
                            : app(ExchangeRateService::class)->getRateAt($debt->wallet->currency, $payment->wallet->currency, $payment->datetime));
                    if ($rate === null || $rate <= 0) {
                        continue;
                    }
                    $capacity = bcdiv($left, (string) $rate, 4);
                    $applied = $paidRate || bccomp($remaining, $capacity, 4) <= 0 ? $remaining : $capacity;
                    if (bccomp((string) $applied, '0', 4) <= 0) {
                        continue;
                    }
                    $this->insert($debt, $payment, (string) $applied, $rate, $paidRate || $debt->wallet->currency === $payment->wallet->currency ? 'paid' : 'market', false, $paidRate ? $left : null);
                    $left = bcsub($left, bcmul((string) $applied, (string) $rate, 4), 4);
                }
            }
        });
    }

    public function explicit(Transaction $payment, array $settles): void
    {
        $intent = TransactionIntent::tryFrom($payment->intent);
        if (! $intent?->isPayment()) {
            throw new HttpException(422, 'Settles may only be specified for a paying-back transaction.');
        }
        DB::table('debt_settlements')->where('payment_transaction_id', $payment->id)->delete();
        $debts = [];
        $sameTotal = '0';
        foreach ($settles as $item) {
            $debt = Transaction::whereKey($item['debt_transaction_id'])->lockForUpdate()->first();
            if (! $debt || ! $payment->hasSameOwner($debt) || $debt->party_id !== $payment->party_id
                || ! in_array($debt->intent, $intent->openingIntents(), true)
                || isset($debts[$debt->id])
                || bccomp((string) $item['amount_applied'], '0', 4) <= 0
                || bccomp((string) $item['amount_applied'], (string) $this->remaining($debt), 4) > 0) {
                throw new HttpException(422, 'Each settlement must reference an open debt of the same owner, party and direction, within its remaining amount.');
            }
            $debts[$debt->id] = [$debt, (string) $item['amount_applied']];
            if ($debt->wallet->currency === $payment->wallet->currency) {
                $sameTotal = bcadd($sameTotal, (string) $item['amount_applied'], 4);
            }
        }
        $foreign = array_filter($debts, fn ($entry) => $entry[0]->wallet->currency !== $payment->wallet->currency);
        if (bccomp($sameTotal, (string) $payment->amount, 4) > 0 || (count($foreign) && bccomp($sameTotal, (string) $payment->amount, 4) >= 0)) {
            throw new HttpException(422, 'Settlements exceed the payment amount.');
        }
        $left = bcsub((string) $payment->amount, $sameTotal, 4);
        foreach ($debts as [$debt, $amount]) {
            $same = $debt->wallet->currency === $payment->wallet->currency;
            $paid = $same || count($foreign) === 1;
            $rate = $same ? 1.0 : ($paid ? bcdiv($left, $amount, 12)
                : app(ExchangeRateService::class)->getRateAt($debt->wallet->currency, $payment->wallet->currency, $payment->datetime));
            if (! $rate || (! $same && bccomp(bcmul($amount, (string) $rate, 4), $left, 4) > 0)) {
                throw new HttpException(422, 'Settlements exceed the payment or need an unavailable exchange rate.');
            }
            $this->insert($debt, $payment, $amount, $rate, $paid ? 'paid' : 'market', true, ! $same && $paid ? $left : null);
            if (! $same) {
                $left = bcsub($left, bcmul($amount, (string) $rate, 4), 4);
            }
        }
    }

    private function oldest(\Illuminate\Database\Eloquent\Builder $query): \Generator
    {
        $last = null;
        do {
            $page = clone $query;
            if ($last) {
                $page->where(fn ($q) => $q->where('datetime', '>', $last->datetime)
                    ->orWhere(fn ($q) => $q->where('datetime', $last->datetime)->where('id', '>', $last->id)));
            }
            $batch = $page->orderBy('datetime')->orderBy('id')->limit(200)->get();
            foreach ($batch as $row) {
                yield $row;
                $last = $row;
            }
        } while ($batch->count() === 200);
    }

    private function insert(Transaction $debt, Transaction $payment, string $amount, string|float $rate, string $source, bool $explicit, ?string $paymentAmount = null): void
    {
        DB::table('debt_settlements')->insert([
            'debt_transaction_id' => $debt->id, 'payment_transaction_id' => $payment->id,
            'amount_applied' => $amount, 'payment_amount' => $paymentAmount ?? bcmul($amount, (string) $rate, 4),
            'rate' => $rate, 'rate_source' => $source,
            'is_explicit' => $explicit, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function remaining(Transaction $debt): string
    {
        $remaining = bcsub(
            (string) ($debt->owed_amount ?? $debt->amount),
            (string) DB::table('debt_settlements')->where('debt_transaction_id', $debt->id)->sum('amount_applied'),
            4
        );
        return bccomp($remaining, '0', 4) > 0 ? $remaining : '0.0000';
    }

    public function fields(Transaction $transaction): array
    {
        $intent = TransactionIntent::tryFrom($transaction->intent ?? 'regular');
        $fields = ['owed_amount' => $transaction->owed_amount];
        if ($intent?->isOpening()) {
            $owed = (float) ($transaction->owed_amount ?? $transaction->amount);
            $remaining = (float) $this->remaining($transaction);
            $fields['debt'] = ['owed' => $owed, 'paid' => $owed - $remaining, 'remaining' => $remaining,
                'currency' => $transaction->wallet->currency, 'status' => $remaining > 0 ? 'open' : 'settled'];
            $fields['settlements'] = DB::table('debt_settlements')->where('debt_transaction_id', $transaction->id)
                ->orderBy('id')->get(['payment_transaction_id', 'amount_applied', 'rate', 'rate_source'])->all();
        }
        if ($intent?->isPayment()) {
            $settles = DB::table('debt_settlements')->join('transactions', 'transactions.id', '=', 'debt_settlements.debt_transaction_id')
                ->join('wallets', 'wallets.id', '=', 'transactions.wallet_id')->where('payment_transaction_id', $transaction->id)
                ->orderBy('debt_settlements.id')->get(['debt_transaction_id', 'amount_applied', 'wallets.currency', 'rate', 'rate_source', 'payment_amount']);
            $fields['settles'] = $settles->map(fn ($item) => collect($item)->except('payment_amount')->all())->all();
            $used = '0';
            foreach ($settles as $settlement) {
                $used = bcadd($used, (string) $settlement->payment_amount, 4);
            }
            $fields['unapplied'] = max(0, (float) bcsub((string) $transaction->amount, $used, 4));
        }
        return $fields;
    }

    public function balances(Party $party): array
    {
        $balances = [];
        $ids = [];
        Transaction::where('owner_type', $party->owner_type)->where('owner_id', $party->owner_id)
            ->where('party_id', $party->id)->whereIn('intent', ['loan_given', 'loan_received', 'debt_owed'])
            ->with('wallet')->orderBy('datetime')->orderBy('id')->chunk(500, function ($debts) use (&$balances, &$ids) {
                foreach ($debts as $debt) {
                    $remaining = $this->remaining($debt);
                    if ($remaining <= 0) {
                        continue;
                    }
                    $ids[] = $debt->id;
                    $currency = $debt->wallet->currency;
                    $balances[$currency] ??= ['currency' => $currency, 'they_owe_me' => 0.0, 'i_owe_them' => 0.0, 'net' => 0.0];
                    $key = $debt->intent === 'loan_given' ? 'they_owe_me' : 'i_owe_them';
                    $balances[$currency][$key] += $remaining;
                    $balances[$currency]['net'] = $balances[$currency]['they_owe_me'] - $balances[$currency]['i_owe_them'];
                }
            });
        return ['balances' => array_values($balances), 'open_debts' => $ids];
    }
}
