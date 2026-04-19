<?php

namespace App\Services;

use App\Models\User;
use App\Types\DuplicateMatch;
use App\Types\TransactionSuggestion;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DuplicateDetectionService
{
    private const DATE_RANGE_DAYS = 3;

    private const AMOUNT_TOLERANCE_PERCENT = 5;

    /**
     * Check a batch of suggestions against the user's existing transactions.
     *
     * @param  TransactionSuggestion[]  $suggestions
     * @return array<int, DuplicateMatch|null> Indexed same as input
     */
    public function checkBatch(array $suggestions, User $user): array
    {
        if (empty($suggestions)) {
            return [];
        }

        $existingTransactions = $this->loadRelevantTransactions($suggestions, $user);
        $results = [];

        foreach ($suggestions as $suggestion) {
            $results[] = $this->findMatch($suggestion, $existingTransactions);
        }

        return $results;
    }

    private function findMatch(TransactionSuggestion $suggestion, Collection $transactions): ?DuplicateMatch
    {
        if ($suggestion->amount === null || $suggestion->date === null) {
            return null;
        }

        try {
            $suggestedDate = Carbon::parse($suggestion->date);
        } catch (\Exception) {
            return null;
        }

        $normalizedDescription = $this->normalizeString($suggestion->description);

        foreach ($transactions as $transaction) {
            $txDate = Carbon::parse($transaction->datetime);
            $txAmount = (float) $transaction->amount;
            $daysDiff = abs($suggestedDate->diffInDays($txDate));

            $matchType = null;
            $matchConfidence = 0;

            // Exact match: same amount + same date + similar description
            if (
                $txAmount == $suggestion->amount
                && $daysDiff === 0
                && $this->descriptionsMatch($normalizedDescription, $this->normalizeString($transaction->description))
            ) {
                $matchType = 'exact';
                $matchConfidence = 1.0;
            } elseif ($txAmount == $suggestion->amount && $daysDiff <= self::DATE_RANGE_DAYS) {
                // Near match: same amount + date within ±3 days
                $matchType = 'near';
                $matchConfidence = 0.8;
            } elseif ($daysDiff <= self::DATE_RANGE_DAYS && $this->amountsAreSimilar($suggestion->amount, $txAmount)) {
                // Similar match: amount within ±5% + date within ±3 days
                $matchType = 'similar';
                $matchConfidence = 0.5;
            }

            if ($matchType !== null) {
                return new DuplicateMatch(
                    transactionId: $transaction->id,
                    matchType: $matchType,
                    confidence: $matchConfidence,
                    transactionAmount: $txAmount,
                    transactionDescription: $transaction->description,
                    transactionDate: $txDate->format('Y-m-d'),
                    transactionType: $transaction->type,
                );
            }
        }

        return null;
    }

    private function loadRelevantTransactions(array $suggestions, User $user): Collection
    {
        $dates = array_filter(array_map(fn (TransactionSuggestion $suggestion) => $suggestion->date, $suggestions));

        if (empty($dates)) {
            return collect();
        }

        $parsedDates = [];
        foreach ($dates as $date) {
            try {
                $parsedDates[] = Carbon::parse($date);
            } catch (\Exception) {
                // skip unparseable dates
            }
        }

        if (empty($parsedDates)) {
            return collect();
        }

        $minDate = min($parsedDates)->copy()->subDays(self::DATE_RANGE_DAYS);
        $maxDate = max($parsedDates)->copy()->addDays(self::DATE_RANGE_DAYS);

        return $user->transactions()
            ->whereBetween('datetime', [$minDate->toDateString(), $maxDate->toDateString()])
            ->select(['id', 'amount', 'description', 'datetime', 'type'])
            ->get();
    }

    private function amountsAreSimilar(float $first, float $second): bool
    {
        if ($first == 0 && $second == 0) {
            return true;
        }

        if ($first == 0 || $second == 0) {
            return false;
        }

        $percentDiff = abs($first - $second) / max(abs($first), abs($second)) * 100;

        return $percentDiff <= self::AMOUNT_TOLERANCE_PERCENT;
    }

    private function descriptionsMatch(?string $first, ?string $second): bool
    {
        if ($first === null || $second === null) {
            return $first === $second;
        }

        if ($first === $second) {
            return true;
        }

        $maxLen = max(strlen($first), strlen($second));
        if ($maxLen === 0) {
            return true;
        }

        $threshold = (int) ($maxLen * 0.2);

        return levenshtein($first, $second) <= $threshold;
    }

    private function normalizeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return strtolower(trim(preg_replace('/\s+/', ' ', $value)));
    }
}
