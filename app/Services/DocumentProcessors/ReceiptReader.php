<?php

namespace App\Services\DocumentProcessors;

use App\Types\TransactionSuggestion;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

/**
 * Reads a store/shop receipt as a single purchase: the printed grand total, the
 * merchant, date and a spending category. Deliberately not the generic
 * "find every transaction" extraction, which returns one row per line item.
 */
class ReceiptReader
{
    use ParsesLlmData;

    public function read(string $rawText): ?TransactionSuggestion
    {
        $provider = config('services.llm.provider', 'groq');
        $model = config('services.llm.model', 'llama-3.1-8b-instant');

        try {
            $llm = Prism::text()
                ->using($provider, $model)
                ->withSystemPrompt(<<<'PROMPT'
You are a receipt parser. The text is one store or shop receipt for a single
purchase. Return ONLY a valid JSON object (no markdown, no other text) with keys:
- amount: number, the receipt's printed grand total (what was actually paid), not the sum of line items
- merchant: string, the store or shop name, or null
- date: string in YYYY-MM-DD format, or null
- category: string, a spending category for the purchase (e.g. Groceries, Dining, Fuel), or null
- description: short string of what was bought, or null
- currency: 3-letter currency code, or null
Use null for anything you cannot determine.
PROMPT)
                ->withPrompt("Parse this receipt:\n\n" . $rawText)
                ->usingTemperature(0)
                ->asText();

            $data = $this->parseLlmJson($llm->text);
            if (! is_array($data)) {
                return null;
            }
            if (isset($data[0]) && is_array($data[0])) {
                $data = $data[0];
            }

            $amount = $data['amount'] ?? null;
            if ($amount === null || (float) $amount <= 0) {
                return null;
            }

            return new TransactionSuggestion(
                amount: abs((float) $amount),
                currency: $data['currency'] ?? null,
                type: 'expense',
                party: $data['merchant'] ?? null,
                category: $data['category'] ?? null,
                description: $data['description'] ?? null,
                date: $this->normalizeDate($data['date'] ?? null),
                confidence: 0.6,
                documentType: 'receipt',
            );
        } catch (\Throwable $e) {
            Log::warning('ReceiptReader: extraction failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
