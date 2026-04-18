<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

            $label = strtolower(trim($response->text, " \t\n\r\0\x0B\"'.,"));
            $route = $label === self::ROUTE_GENERAL ? self::ROUTE_GENERAL : self::ROUTE_DATA;

            Log::debug('AiRouter classified question', [
                'question' => $question,
                'raw' => $response->text,
                'route' => $route,
            ]);

            return $route;
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

    public function generateTitle(string $firstQuestion): ?string
    {
        try {
            $response = Prism::text()
                ->using(
                    config('services.llm.provider'),
                    config('services.llm.model'),
                )
                ->withSystemPrompt($this->titleSystemPrompt())
                ->withPrompt($firstQuestion)
                ->usingTemperature(0.3)
                ->asText();

            $title = trim($response->text);
            $title = trim($title, "\"'.,;:!?\n\r\t ");

            if ($title === '') {
                return null;
            }

            return Str::limit($title, 80, '');
        } catch (Throwable $e) {
            Log::warning('AiRouter generateTitle failed', ['message' => $e->getMessage()]);

            return null;
        }
    }

    private function classifierSystemPrompt(): string
    {
        return <<<'PROMPT'
Route the user's question for a personal finance assistant. Trakli has
live access to the user's financial records (transactions, wallets,
categories, parties, transfers, balances) through a data layer.

Reply with EXACTLY one word: data OR general. No quotes, no punctuation,
no explanation. Lowercase.

Reply "data" for ANY question that would look up the user's own records:
  - Spending: "what did I spend on food", "how much did I spend last month"
  - Income: "my income this year", "how much did I earn in June"
  - Wallets / balances: "what wallet has the most money", "my balance"
  - Categories: "top categories", "biggest category last week"
  - Transactions: "recent transactions", "what did I buy yesterday"
  - Totals / comparisons: "total expenses", "am I spending more than last month"
  - Anything using "my", "I", "me", "last", "recent", "most", with a
    finance term (spend, expense, income, balance, wallet, category,
    transaction, transfer, budget, savings)

Reply "general" ONLY for:
  - Greetings / small talk: "hi", "hello", "how are you"
  - Definitions of finance terms: "what is compound interest"
  - How-to questions about the app itself: "how do I add a wallet"

When uncertain, reply "data". The data layer can report "no matching
results" — that's fine. Missing a real data question is worse than a
useless data lookup.
PROMPT;
    }

    private function generalSystemPrompt(?string $dataFailureHint): string
    {
        $base = 'You are Trakli, a personal finance assistant. Be concise '
            . 'and friendly. Trakli has a live data layer with the user\'s '
            . 'transactions, wallets, categories, parties, transfers and '
            . 'balances — you do NOT see that data in this conversation, '
            . 'but it exists and is available through Trakli.';

        if ($dataFailureHint === null) {
            $base .= ' The current question was routed to you because it '
                . 'looked conversational (a greeting, a definition, or app '
                . 'help). Answer it directly. If it is actually about the '
                . 'user\'s own records, tell the user to retry rephrasing '
                . 'clearly (for example: "How much did I spend last month?") '
                . 'so Trakli can query the data layer — do NOT claim you '
                . 'lack access to their data.';
        } else {
            $base .= ' A prior attempt to query the user\'s data failed with: '
                . $dataFailureHint
                . '. Apologise briefly, explain the lookup did not work, and '
                . 'suggest a rephrasing the user could try. Do NOT claim you '
                . 'lack access to their data in general — only that this '
                . 'specific query did not succeed.';
        }

        return $base;
    }

    private function titleSystemPrompt(): string
    {
        return 'Generate a concise chat title (max 6 words) summarizing the '
            . 'topic of the given question. Respond with only the title — '
            . 'no quotes, no trailing punctuation, no explanation.';
    }
}
