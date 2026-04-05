<?php

namespace App\Services;

use App\Models\User;
use App\Types\TransactionSuggestion;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

class SuggestionEnricher
{
    /**
     * Enrich raw transaction suggestions by mapping them to the user's
     * existing wallets, categories, and parties using an LLM.
     *
     * @param  TransactionSuggestion[]  $suggestions
     * @return TransactionSuggestion[]
     */
    public function enrich(array $suggestions, User $user): array
    {
        if (empty($suggestions)) {
            return [];
        }

        $wallets = $user->wallets()->select('name', 'currency')->get()->toArray();
        $categories = $user->categories()->select('name', 'type')->get()->toArray();
        $parties = $user->parties()->select('name')->get()->toArray();

        // If user has no existing entities, skip LLM call — nothing to match against
        // but still try to classify type and clean descriptions
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

        $prompt = $this->buildPrompt($suggestionsData, $wallets, $categories, $parties);

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
                return $this->mergeEnrichments($suggestions, $enriched);
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
You are a financial transaction classifier.

CRITICAL RULES:
- wallet: MUST match by currency. A EUR transaction can ONLY go to a EUR wallet.
  A USD transaction can ONLY go to a USD wallet. NEVER assign a mismatched currency.
  Use null if no wallet has the right currency.
- category: Use an existing category if the description matches. Use null if nothing fits.
- party: Use an existing party if the merchant/sender matches. Use null if nothing fits.
- type: "income" for money received, "expense" for money spent.
- description: Clean up raw text (e.g. "AMZN MKTP US*ABC123" → "Amazon Marketplace").

OUTPUT FORMAT:
- Respond with ONLY a valid JSON array. No other text, no markdown code fences.
- Each element must have exactly these keys: wallet, category, party, type, description.
- Use null (not empty string) when no match is found.
PROMPT;
    }

    private function buildPrompt(array $suggestions, array $wallets, array $categories, array $parties): string
    {
        $parts = [];

        $parts[] = '=== AVAILABLE WALLETS (use ONLY these, matched by currency) ===';
        if (! empty($wallets)) {
            foreach ($wallets as $wallet) {
                $parts[] = "- \"{$wallet['name']}\" (currency: {$wallet['currency']})";
            }
        } else {
            $parts[] = 'None — set wallet to null for all transactions.';
        }

        $parts[] = "\n=== AVAILABLE CATEGORIES ===";
        if (! empty($categories)) {
            foreach ($categories as $category) {
                $parts[] = "- \"{$category['name']}\" (type: {$category['type']})";
            }
        } else {
            $parts[] = 'None — set category to null.';
        }

        $parts[] = "\n=== AVAILABLE PARTIES ===";
        if (! empty($parties)) {
            foreach ($parties as $party) {
                $parts[] = "- \"{$party['name']}\"";
            }
        } else {
            $parts[] = 'None — set party to null.';
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
    private function mergeEnrichments(array $suggestions, array $enrichments): array
    {
        $result = [];

        foreach ($suggestions as $i => $suggestion) {
            $enrichment = $enrichments[$i] ?? [];

            $result[] = new TransactionSuggestion(
                amount: $suggestion->amount,
                currency: $suggestion->currency,
                type: $enrichment['type'] ?? $suggestion->type,
                party: $enrichment['party'] ?? $suggestion->party,
                wallet: $enrichment['wallet'] ?? $suggestion->wallet,
                category: $enrichment['category'] ?? $suggestion->category,
                description: $enrichment['description'] ?? $suggestion->description,
                date: $suggestion->date,
                confidence: $suggestion->confidence,
                documentType: $suggestion->documentType,
            );
        }

        return $result;
    }
}
