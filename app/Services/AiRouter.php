<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;
use Throwable;

class AiRouter
{
    public const ROUTE_DATA = 'data';

    public const ROUTE_GENERAL = 'general';

    public function classify(string $question): string
    {
        try {
            $response = Prism::text()
                ->using(
                    config('services.llm.provider'),
                    config('services.llm.model'),
                )
                ->withSystemPrompt($this->classifierSystemPrompt())
                ->withPrompt($question)
                ->usingTemperature(0)
                ->asText();

            $label = strtolower(trim($response->text));

            return $label === self::ROUTE_GENERAL ? self::ROUTE_GENERAL : self::ROUTE_DATA;
        } catch (Throwable $e) {
            Log::warning('AiRouter classify failed; defaulting to data route', [
                'message' => $e->getMessage(),
            ]);

            return self::ROUTE_DATA;
        }
    }

    public function answerGeneral(string $question, ?string $dataFailureHint = null): array
    {
        try {
            $response = Prism::text()
                ->using(
                    config('services.llm.provider'),
                    config('services.llm.model'),
                )
                ->withSystemPrompt($this->generalSystemPrompt($dataFailureHint))
                ->withPrompt($question)
                ->usingTemperature(0.3)
                ->asText();

            return [
                'success' => true,
                'text' => $response->text,
            ];
        } catch (Throwable $e) {
            Log::error('AiRouter answerGeneral failed', ['message' => $e->getMessage()]);

            return [
                'success' => false,
                'error' => __('AI service is currently unavailable. Please try again later.'),
            ];
        }
    }

    private function classifierSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a router that classifies user questions for a personal finance assistant.

Reply with EXACTLY one lowercase word — no punctuation, no explanation:

- "data"    → the question requires querying the user's own financial data
              (their transactions, wallets, categories, balances, spending,
              income, transfers, parties, dates, amounts, totals, comparisons
              over time, etc.)
- "general" → the question does NOT require the user's data (greetings,
              definitions, general financial advice, how-to questions about
              the app, conceptual questions, small talk)

When in doubt, reply "data".
PROMPT;
    }

    private function generalSystemPrompt(?string $dataFailureHint): string
    {
        $base = 'You are Trakli, a helpful personal finance assistant. '
            . 'Be concise and friendly. If the user asks about their own '
            . 'financial data and you do not have access to it, say so plainly '
            . 'and suggest how they might rephrase.';

        if ($dataFailureHint) {
            $base .= ' Note: a prior attempt to query the user\'s data failed: '
                . $dataFailureHint;
        }

        return $base;
    }
}
