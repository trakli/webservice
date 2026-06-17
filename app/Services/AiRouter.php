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

    public const ROUTE_AGENT = 'agent';

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
            $route = match ($label) {
                self::ROUTE_GENERAL => self::ROUTE_GENERAL,
                self::ROUTE_AGENT => self::ROUTE_AGENT,
                default => self::ROUTE_DATA,
            };

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
categories, parties, transfers, balances) through a data layer, and can
ALSO take actions on the user's behalf (create or change records).

Reply with EXACTLY one word: data OR general OR agent. No quotes, no
punctuation, no explanation. Lowercase.

Reply "agent" when the user wants to DO or CHANGE something, not just learn:
  - Recording: "log 20 for coffee", "add an expense of 50", "record income"
  - Creating: "create a wallet called Cash", "add a category Groceries",
    "make a new party"
  - Changing: "categorize that as food", "rename my wallet", "delete that
    transaction", "update the amount to 30"
  - Moving money: "transfer 100 from Cash to Bank"
  - Importing: "import this statement", "add the transactions from this receipt"
  - Any imperative verb acting on their data: log, add, record, create, make,
    set, change, update, rename, delete, remove, categorize, transfer, import.

Also reply "agent" when the user wants RICH or VISUAL output rather than a
single number or sentence — anything that needs presentation, not just a lookup:
  - Reports: "write a report about my spending", "a proper report with graphs"
  - Charts / graphs: "show me a chart of my spending", "graph my cash flow"
  - Dashboards / visual breakdowns: "build a dashboard", "visualize my expenses"
  - Tables to act on, or analysis that combines querying with presentation:
    "a table of my top spend and a personality trait", "compare months visually"
  - Any request mentioning report, chart, graph, plot, dashboard, visualize,
    visualization, breakdown, or "with graphs/visuals".

Reply "data" for a PLAIN question that just needs a number, list or short answer
from the user's own records (no report/chart/visual framing):
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
