<?php

namespace App\Services;

use App\Models\User;
use App\Types\TransactionSuggestion;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

class SuggestionEnricher
{
    /**
     * Enrich raw transaction suggestions by mapping them to the user's existing
     * wallets, categories, and parties. When the source document context is
     * supplied, it also validates the parsed amount, currency, and date against
     * that document so a mis-extracted value (e.g. an account number picked up
     * as an amount) gets corrected. Uses an LLM; when it is unavailable the raw
     * suggestions are returned unchanged.
     *
     * @param  TransactionSuggestion[]  $suggestions
     * @param  string|null  $documentContext  Raw extracted text/tables from the
     *                                        source document, used as ground
     *                                        truth for value correction.
     * @return TransactionSuggestion[]
     */
    public function enrich(array $suggestions, User $user, ?string $documentContext = null): array
    {
        if (empty($suggestions)) {
            return [];
        }

        $wallets = $user->wallets()->select('name', 'currency', 'slug')->get()->toArray();
        $categories = $user->categories()->select('name', 'type')->get()->toArray();
        $parties = $user->parties()->select('name')->get()->toArray();

        // With no existing entities there is nothing to match against; the LLM
        // still classifies type and cleans descriptions.
        $suggestionsData = array_map(fn (TransactionSuggestion $suggestion) => [
            'amount' => $suggestion->amount,
            'currency' => $suggestion->currency,
            'type' => $suggestion->type,
            'party' => $suggestion->party,
            'wallet' => $suggestion->wallet,
            'category' => $suggestion->category,
            'description' => $suggestion->description,
            'date' => $suggestion->date,
        ], $suggestions);

        $prompt = $this->buildPrompt($suggestionsData, $wallets, $categories, $parties, $documentContext);

        try {
            $response = Prism::text()
                ->using(
                    config('services.llm.provider', 'groq'),
                    config('services.llm.model', 'llama-3.1-8b-instant'),
                )
                ->withSystemPrompt($this->systemPrompt())
                ->withPrompt($prompt)
                ->usingTemperature(0)
                ->asText();

            $enriched = $this->parseResponse($response->text);

            if (is_array($enriched) && count($enriched) === count($suggestions)) {
                return $this->mergeEnrichments($suggestions, $enriched, $wallets);
            }

            Log::warning('SuggestionEnricher: LLM response count mismatch', [
                'expected' => count($suggestions),
                'got' => is_array($enriched) ? count($enriched) : 'invalid',
            ]);
        } catch (\Throwable $e) {
            Log::warning('SuggestionEnricher: LLM unavailable, skipping enrichment', ['error' => $e->getMessage()]);
        }

        return $suggestions;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a financial transaction classifier and validator.

CRITICAL RULES:
- wallet: MUST match by currency. A EUR transaction can ONLY go to a EUR wallet.
  A USD transaction can ONLY go to a USD wallet. NEVER assign a mismatched currency.
  Use null if no wallet has the right currency.
- category: Use an existing category if the description matches. Use null if nothing fits.
- party: Use an existing party if the merchant/sender matches. Use null if nothing fits.
- type: "income" for money received, "expense" for money spent.
- description: Clean up raw text (e.g. "AMZN MKTP US*ABC123" → "Amazon Marketplace").

VALUE VALIDATION (amount, currency, date):
- A SOURCE DOCUMENT section may be provided below. When it is, use it as ground
  truth to correct values that were mis-extracted.
- amount: a positive number, the transaction's monetary value. If the provided
  amount is actually an account number, phone number, reference or balance (NOT
  the transaction value), replace it with the correct amount from the SOURCE
  DOCUMENT. Never return a negative amount; direction is carried by "type".
- currency: a 3-letter code; correct it only if the SOURCE DOCUMENT clearly shows
  a different currency.
- date: YYYY-MM-DD; correct it only if the SOURCE DOCUMENT clearly shows a
  different date.
- If NO SOURCE DOCUMENT section is present, echo amount, currency and date back
  EXACTLY as given; do not guess.

OUTPUT FORMAT:
- Respond with ONLY a valid JSON array. No other text, no markdown code fences.
- Each element must have exactly these keys: wallet, category, party, type,
  description, amount, currency, date.
- Use null (not empty string) when no match is found for wallet, category or party.
PROMPT;
    }

    private function buildPrompt(
        array $suggestions,
        array $wallets,
        array $categories,
        array $parties,
        ?string $documentContext = null,
    ): string {
        $parts = [];

        if (! empty($documentContext)) {
            $parts[] = '=== SOURCE DOCUMENT (ground truth for amount, currency, date) ===';
            $parts[] = mb_substr($documentContext, 0, 8000);
        }

        $parts[] = '=== AVAILABLE WALLETS (use ONLY these, matched by currency) ===';
        if (! empty($wallets)) {
            foreach ($wallets as $wallet) {
                $parts[] = "- \"{$wallet['name']}\" (currency: {$wallet['currency']})";
            }
        } else {
            $parts[] = 'None available; set wallet to null for all transactions.';
        }

        $parts[] = "\n=== AVAILABLE CATEGORIES ===";
        if (! empty($categories)) {
            foreach ($categories as $category) {
                $parts[] = "- \"{$category['name']}\" (type: {$category['type']})";
            }
        } else {
            $parts[] = 'None available; set category to null.';
        }

        $parts[] = "\n=== AVAILABLE PARTIES ===";
        if (! empty($parties)) {
            foreach ($parties as $party) {
                $parts[] = "- \"{$party['name']}\"";
            }
        } else {
            $parts[] = 'None available; set party to null.';
        }

        $count = count($suggestions);
        $parts[] = "\n=== TRANSACTIONS TO CLASSIFY ({$count}) ===";
        $parts[] = json_encode($suggestions);

        $parts[] = "\nReturn exactly {$count} JSON objects.";

        return implode("\n\n", $parts);
    }

    private function parseResponse(string $text): ?array
    {
        $text = trim($text);

        // Strip markdown code fences if present
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
        }

        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            Log::warning('SuggestionEnricher: Failed to parse LLM JSON', [
                'error' => json_last_error_msg(),
                'response' => substr($text, 0, 500),
            ]);

            return null;
        }

        return $decoded;
    }

    /**
     * @param  TransactionSuggestion[]  $suggestions
     * @return TransactionSuggestion[]
     */
    private function mergeEnrichments(array $suggestions, array $enrichments, array $wallets): array
    {
        $walletsBySlug = [];
        $slugByName = [];
        foreach ($wallets as $w) {
            $walletsBySlug[$w['slug']] = $w;
            $slugByName[$w['name']] = $w['slug'];
        }

        $result = [];

        foreach ($suggestions as $i => $suggestion) {
            $enrichment = $enrichments[$i] ?? [];
            $assignedWallet = $enrichment['wallet'] ?? $suggestion->wallet;

            // Value corrections are grounded in the source document; fall back to
            // the originally parsed value whenever the correction is unusable.
            $amount = $this->correctedAmount($enrichment['amount'] ?? null, $suggestion->amount);
            $date = $this->correctedDate($enrichment['date'] ?? null, $suggestion->date);

            $currency = $this->correctedCurrency($enrichment['currency'] ?? null, $suggestion->currency);
            if ($assignedWallet) {
                $slug = $slugByName[$assignedWallet] ?? null;
                if ($slug && isset($walletsBySlug[$slug])) {
                    $currency = $walletsBySlug[$slug]['currency'];
                }
            }

            $result[] = new TransactionSuggestion(
                amount: $amount,
                currency: $currency,
                type: $enrichment['type'] ?? $suggestion->type,
                party: $enrichment['party'] ?? $suggestion->party,
                wallet: $assignedWallet,
                category: $enrichment['category'] ?? $suggestion->category,
                description: $enrichment['description'] ?? $suggestion->description,
                date: $date,
                confidence: $suggestion->confidence,
                documentType: $suggestion->documentType,
            );
        }

        return $result;
    }

    /**
     * Accept a corrected amount only when it is a usable positive number;
     * otherwise keep the originally parsed value.
     */
    private function correctedAmount(mixed $corrected, ?float $original): ?float
    {
        if (is_numeric($corrected)) {
            $value = abs((float) $corrected);
            if ($value > 0) {
                return $value;
            }
        }

        return $original;
    }

    private function correctedDate(mixed $corrected, ?string $original): ?string
    {
        if (is_string($corrected) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($corrected))) {
            return trim($corrected);
        }

        return $original;
    }

    private function correctedCurrency(mixed $corrected, ?string $original): ?string
    {
        if (is_string($corrected) && preg_match('/^[A-Za-z]{3}$/', trim($corrected))) {
            return strtoupper(trim($corrected));
        }

        return $original;
    }
}
