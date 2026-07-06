<?php

namespace App\Services;

use App\Types\TransactionSuggestion;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

/**
 * Header-aware structured extraction from a statement's raw text.
 *
 * Statement extractors (OCR/table services) collapse each row into one
 * delimiter-less string, so the column each value belongs to can only be
 * recovered by reading the header semantically. This sends the raw text
 * (header included) to an LLM and gets back one structured object per
 * transaction, disambiguating the amount from phone numbers, transaction ids,
 * fees and balances that share the row.
 *
 * When the LLM is unavailable or its response cannot be parsed, this returns an
 * empty array so the caller can fall back to its previous extraction path. It
 * never throws.
 */
class StatementStructurer
{
    private const MAX_INPUT_CHARS = 12000;

    /**
     * @return TransactionSuggestion[]
     */
    public function structure(string $rawText, ?string $documentType = null): array
    {
        $rawText = trim($rawText);
        if ($rawText === '') {
            return [];
        }

        try {
            $response = Prism::text()
                ->using(
                    config('services.llm.provider', 'groq'),
                    config('services.llm.model', 'llama-3.1-8b-instant'),
                )
                ->withSystemPrompt($this->systemPrompt())
                ->withPrompt($this->buildPrompt($rawText, $documentType))
                ->usingTemperature(0)
                ->asText();

            $rows = $this->parseJson($response->text);

            if (! is_array($rows)) {
                return [];
            }

            return $this->toSuggestions($rows);
        } catch (\Throwable $e) {
            Log::warning('StatementStructurer: LLM unavailable, skipping structured extraction', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You extract transactions from the raw text of a financial statement.

The text is extracted from a table, so each transaction's values may run
together on one line with no separators. A header line names the columns; use it
to decide which value is which. Statements commonly have these columns: date &
time, payment type, to/from, account, name, amount, transaction id, fees, tax,
balance, reference.

DISAMBIGUATION (critical):
- amount is the transaction's monetary value. It is NOT the phone number
  (e.g. "+237 68 09 11 37 4"), NOT the transaction id (a long 10+ digit number),
  NOT the fee, tax or balance. When a row has several numbers, the amount is the
  one in the amount column, usually signed (e.g. "-2000" or "+150000").
- direction: "expense" when money leaves the account (debit, negative amount,
  payment/transfer/withdrawal/airtime), "income" when money arrives (credit,
  positive amount, deposit/received).
- counterparty_name is the person or business name, NOT the payment type.
- account is the phone number or account identifier of the counterparty.

For EACH transaction return an object with these keys:
- date: "YYYY-MM-DD"
- description: a short human-readable summary (payment type and counterparty)
- payment_type: the raw payment type (e.g. "MOMO USER", "AIRTIME", "CASH OUT")
- counterparty_name: the other party's name, or null
- account: the counterparty phone/account, or null
- amount: a positive number (the transaction value)
- direction: "income" or "expense"
- fee: a number, or null if none
- tax: a number, or null if none
- balance: a number, or null
- reference: the reference/transaction id text, or null
- currency: 3-letter code (e.g. "XAF"), or null

Respond with ONLY a JSON array. No prose, no markdown fences. Skip header,
subtotal and footer lines that are not transactions.
PROMPT;
    }

    private function buildPrompt(string $rawText, ?string $documentType): string
    {
        $parts = [];

        if (! empty($documentType)) {
            $parts[] = "Document type: {$documentType}";
        }

        $parts[] = "Statement text:\n\n" . mb_substr($rawText, 0, self::MAX_INPUT_CHARS);

        return implode("\n\n", $parts);
    }

    private function parseJson(string $text): ?array
    {
        $text = trim($text);

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
        }

        $decoded = json_decode($text, true);

        return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : null;
    }

    /**
     * @return TransactionSuggestion[]
     */
    private function toSuggestions(array $rows): array
    {
        $suggestions = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $amount = $this->number($row['amount'] ?? null);
            $date = $this->normalizeDate($row['date'] ?? null);

            if ($amount === null || $amount == 0.0 || $date === null) {
                continue;
            }

            $direction = strtolower((string) ($row['direction'] ?? ''));
            $type = in_array($direction, ['income', 'expense'], true)
                ? $direction
                : ($amount < 0 ? 'expense' : 'income');

            $suggestions[] = new TransactionSuggestion(
                amount: abs($amount),
                currency: $this->currency($row['currency'] ?? null),
                type: $type,
                party: $this->text($row['counterparty_name'] ?? null),
                description: $this->text($row['description'] ?? null) ?? $this->text($row['payment_type'] ?? null),
                date: $date,
                confidence: 0.8,
                documentType: 'statement',
                fee: $this->number($row['fee'] ?? null),
                tax: $this->number($row['tax'] ?? null),
                account: $this->text($row['account'] ?? null),
                reference: $this->text($row['reference'] ?? null),
            );
        }

        return $suggestions;
    }

    private function number(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $cleaned = str_replace(',', '', trim($value));
            if (is_numeric($cleaned)) {
                return (float) $cleaned;
            }
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function currency(mixed $value): ?string
    {
        if (is_string($value) && preg_match('/^[A-Za-z]{3}$/', trim($value))) {
            return strtoupper(trim($value));
        }

        return null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        try {
            return Carbon::parse(str_replace(',', '', $value))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
