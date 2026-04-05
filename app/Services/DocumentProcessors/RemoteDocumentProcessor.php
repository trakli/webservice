<?php

namespace App\Services\DocumentProcessors;

use App\Contracts\DocumentProcessor;
use App\Models\User;
use App\Types\TransactionSuggestion;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

/**
 * Generic remote document processor.
 *
 * Sends files to any configured URL and parses the response using a
 * configurable field mapping. Works with any document processing service.
 *
 * Configuration (config/services.php → 'document_processor'):
 *   - url: The endpoint URL
 *   - timeout: Request timeout in seconds
 *   - auth_type: none|bearer|apikey|basic
 *   - auth_credentials: Token, API key, or "user:pass"
 *   - file_field: Form field name for the file (default: "file")
 *   - extra_params: Additional form parameters to send
 *   - response_mapping:
 *       transactions_path: dot-notation path to the array of items (e.g. "transactions", "elements", "data.rows")
 *       mode: "fields" (each item has named keys) or "text_block" (each item has a text blob to parse by line)
 *       --- fields mode ---
 *       date_field, description_field, amount_field, currency_field, type_field, party_field, category_field, confidence_field
 *       --- text_block mode ---
 *       content_field: key containing the text block (default: "content")
 *       line_mapping: { date: 0, description: 1, amount: 2, currency: 3 } (line index per field)
 *       filter: { key: "subtype", value: "paragraph" } (optional, only process items matching this)
 */
class RemoteDocumentProcessor implements DocumentProcessor
{
    private const SUPPORTED_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'tif', 'bmp'];

    private const SUPPORTED_MIMES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/tiff',
        'image/bmp',
    ];

    public function supports(string $mimeType, string $extension): bool
    {
        $url = config('services.document_processor.url');

        if (empty($url)) {
            return false;
        }

        return in_array($extension, self::SUPPORTED_EXTENSIONS)
            || in_array($mimeType, self::SUPPORTED_MIMES);
    }

    /**
     * @return TransactionSuggestion[]
     */
    public function process(UploadedFile $file, User $user): array
    {
        $url = config('services.document_processor.url');

        if (empty($url)) {
            return [];
        }

        $response = $this->callRemote($file);

        if ($response === null) {
            return [];
        }

        $suggestions = $this->parseResponse($response);

        // Fallback: if mapping couldn't extract transactions, send raw data to LLM
        if (empty($suggestions)) {
            $rawText = $this->extractRawText($response);
            if (! empty($rawText)) {
                Log::info('RemoteDocumentProcessor: mapping returned empty, falling back to LLM');
                $suggestions = $this->extractViaLlm($rawText);
            }
        }

        return $suggestions;
    }

    private function callRemote(UploadedFile $file): ?array
    {
        $url = config('services.document_processor.url');
        $timeout = (int) config('services.document_processor.timeout', 120);
        $fileField = config('services.document_processor.file_field', 'file');
        $extraParams = config('services.document_processor.extra_params', []);

        try {
            $authType = config('services.document_processor.auth_type', 'none');
            $credentials = config('services.document_processor.auth_credentials', '');
            $authHeader = config('services.document_processor.auth_header', 'X-API-Key');

            $fileHandle = fopen($file->getRealPath(), 'r');

            try {
                $request = Http::timeout($timeout)
                    ->attach($fileField, $fileHandle, $file->getClientOriginalName());

                if ($authType === 'bearer') {
                    $request = $request->withToken($credentials);
                } elseif ($authType === 'apikey' && $credentials) {
                    $request = $request->withHeaders([$authHeader => $credentials]);
                } elseif ($authType === 'basic') {
                    $request = $request->withBasicAuth(...explode(':', $credentials, 2));
                }

                $response = $request->post($url, $extraParams);
            } finally {
                if (is_resource($fileHandle)) {
                    fclose($fileHandle);
                }
            }

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('RemoteDocumentProcessor: request failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);
        } catch (\Throwable $e) {
            Log::error('RemoteDocumentProcessor: request error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function parseResponse(array $response): array
    {
        $mapping = config('services.document_processor.response_mapping', []);
        $mode = $mapping['mode'] ?? 'fields';

        $transactionsPath = $mapping['transactions_path'] ?? 'transactions';
        $items = Arr::get($response, $transactionsPath, []);

        if (! is_array($items) || empty($items)) {
            Log::info('RemoteDocumentProcessor: no items at path', [
                'path' => $transactionsPath,
                'keys' => array_keys($response),
            ]);

            return [];
        }

        // Optional filter: only process items matching a condition
        $filter = $mapping['filter'] ?? null;
        if ($filter && isset($filter['key'], $filter['value'])) {
            $items = array_filter($items, fn ($item) => Arr::get($item, $filter['key']) === $filter['value']);
        }

        return match ($mode) {
            'text_block' => $this->parseTextBlocks($items, $mapping),
            default => $this->parseFields($items, $mapping),
        };
    }

    /**
     * Mode: fields — each item has named keys for date, amount, etc.
     */
    private function parseFields(array $items, array $mapping): array
    {
        $dateField = $mapping['date_field'] ?? 'date';
        $descriptionField = $mapping['description_field'] ?? 'description';
        $amountField = $mapping['amount_field'] ?? 'amount';
        $currencyField = $mapping['currency_field'] ?? 'currency';
        $typeField = $mapping['type_field'] ?? 'type';
        $partyField = $mapping['party_field'] ?? 'party';
        $categoryField = $mapping['category_field'] ?? 'category';
        $confidenceField = $mapping['confidence_field'] ?? 'confidence';

        $suggestions = [];

        foreach ($items as $tx) {
            if (! is_array($tx)) {
                continue;
            }

            $amount = Arr::get($tx, $amountField);
            if ($amount === null || $amount === '') {
                continue;
            }

            $rawAmount = (float) str_replace(',', '', (string) $amount);
            $type = Arr::get($tx, $typeField);

            if (empty($type)) {
                $type = $rawAmount < 0 ? 'expense' : 'income';
            }

            $date = $this->normalizeDate(Arr::get($tx, $dateField));
            if ($date === null) {
                continue;
            }

            $suggestions[] = new TransactionSuggestion(
                amount: abs($rawAmount),
                currency: Arr::get($tx, $currencyField),
                type: $type,
                party: Arr::get($tx, $partyField),
                category: Arr::get($tx, $categoryField),
                description: Arr::get($tx, $descriptionField),
                date: $date,
                confidence: (float) (Arr::get($tx, $confidenceField) ?? 0.8),
                documentType: 'remote',
            );
        }

        return $suggestions;
    }

    /**
     * Mode: text_block — each item has a text blob, split by newlines, mapped by line index.
     *
     * Example: content = "31 Mar, 2024\nCard charge (Starlink)\n-36.12\nEUR\n3437.35"
     * With line_mapping: { date: 0, description: 1, amount: 2, currency: 3 }
     */
    private function parseTextBlocks(array $items, array $mapping): array
    {
        $contentField = $mapping['content_field'] ?? 'content';
        $lineMapping = $mapping['line_mapping'] ?? [
            'date' => 0,
            'description' => 1,
            'amount' => 2,
            'currency' => 3,
        ];

        $suggestions = [];

        foreach ($items as $item) {
            $suggestion = $this->parseTextBlockItem($item, $contentField, $lineMapping);
            if ($suggestion !== null) {
                $suggestions[] = $suggestion;
            }
        }

        return $suggestions;
    }

    private function parseTextBlockItem(mixed $item, string $contentField, array $lineMapping): ?TransactionSuggestion
    {
        $content = is_array($item) ? Arr::get($item, $contentField, '') : (string) $item;
        $lines = array_map('trim', explode("\n", trim($content)));

        $date = $this->getLine($lines, $lineMapping['date'] ?? null);
        $amount = $this->getLine($lines, $lineMapping['amount'] ?? null);

        if ($date === null || $amount === null) {
            return null;
        }

        $normalizedDate = $this->normalizeDate($date);
        if ($normalizedDate === null) {
            return null;
        }

        $rawAmount = (float) str_replace(',', '', $amount);
        if ($rawAmount == 0) {
            return null;
        }

        if ($this->isSkippedStatus($this->getLine($lines, $lineMapping['status'] ?? null))) {
            return null;
        }

        $description = $this->getLine($lines, $lineMapping['description'] ?? null) ?? '';
        $currency = $this->getLine($lines, $lineMapping['currency'] ?? null);

        return new TransactionSuggestion(
            amount: abs($rawAmount),
            currency: $currency,
            type: $rawAmount < 0 ? 'expense' : 'income',
            party: $this->extractPartyFromDescription($description),
            description: $description,
            date: $normalizedDate,
            confidence: 0.8,
            documentType: 'remote',
        );
    }

    private function isSkippedStatus(?string $status): bool
    {
        return $status !== null && in_array(strtolower($status), ['canceled', 'cancelled', 'failed', 'reversed']);
    }

    /**
     * Extract party from parentheses in description: "Card charge (VENDOR)" -> "VENDOR"
     */
    private function extractPartyFromDescription(string $description): ?string
    {
        if (preg_match('/\(([^)]+)\)/', $description, $partyMatch)) {
            return $partyMatch[1];
        }

        return null;
    }

    private function getLine(array $lines, ?int $index): ?string
    {
        if ($index === null || ! isset($lines[$index])) {
            return null;
        }

        $val = trim($lines[$index]);

        return $val !== '' ? $val : null;
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        // Remove commas that confuse Carbon (e.g. "05 Apr, 2024" → "05 Apr 2024")
        $value = str_replace(',', '', $value);

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Extract all readable text from the processor response for LLM fallback.
     */
    private function extractRawText(array $response): string
    {
        $parts = [];

        // Try elements (common in OCR responses)
        foreach ($response['elements'] ?? [] as $element) {
            $content = $element['content'] ?? $element['text'] ?? null;
            if ($content) {
                $parts[] = $content;
            }
        }

        // Try raw_text field
        if (empty($parts) && ! empty($response['raw_text'])) {
            return $response['raw_text'];
        }

        // Try tables
        foreach ($response['tables'] ?? [] as $table) {
            foreach ($table['headers'] ?? [] as $h) {
                $parts[] = $h;
            }
            foreach ($table['rows'] ?? [] as $row) {
                $parts[] = implode(' | ', $row);
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Use LLM to extract transactions from raw unstructured text.
     *
     * @return TransactionSuggestion[]
     */
    private function extractViaLlm(string $rawText): array
    {
        $provider = config('services.llm.provider', 'groq');
        $model = config('services.llm.model', 'llama-3.1-8b-instant');

        // Truncate to avoid token limits
        $rawText = mb_substr($rawText, 0, 8000);

        try {
            $response = Prism::text()
                ->using($provider, $model)
                ->withSystemPrompt(<<<'PROMPT'
You are a financial document parser. Given raw text extracted from a financial
document (bank statement, receipt, invoice), extract all transactions you can find.

Return ONLY a valid JSON array. No other text, no markdown fences.
Each transaction must have these keys:
- date: string in YYYY-MM-DD format
- description: string describing the transaction
- amount: number (negative for expenses/debits, positive for income/credits)
- currency: 3-letter currency code (e.g. USD, EUR)

If you cannot determine a field, use null. Skip non-transaction text (headers, footers, account info).
PROMPT)
                ->withPrompt("Extract transactions from this document text:\n\n" . $rawText)
                ->usingTemperature(0)
                ->asText();

            $parsed = $this->parseLlmJson($response->text);

            if (! is_array($parsed) || empty($parsed)) {
                return [];
            }

            $suggestions = [];
            foreach ($parsed as $tx) {
                $amount = $tx['amount'] ?? null;
                if ($amount === null) {
                    continue;
                }

                $rawAmount = (float) $amount;
                $date = $this->normalizeDate($tx['date'] ?? null);

                $description = $tx['description'] ?? '';
                $party = null;
                if (preg_match('/\(([^)]+)\)/', $description, $match)) {
                    $party = $match[1];
                }

                $suggestions[] = new TransactionSuggestion(
                    amount: abs($rawAmount),
                    currency: $tx['currency'] ?? null,
                    type: $rawAmount < 0 ? 'expense' : 'income',
                    party: $party,
                    description: $description,
                    date: $date,
                    confidence: 0.6,
                    documentType: 'llm_fallback',
                );
            }

            return $suggestions;
        } catch (\Throwable $e) {
            Log::warning('RemoteDocumentProcessor: LLM fallback unavailable', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function parseLlmJson(string $text): ?array
    {
        $text = trim($text);

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
        }

        $decoded = json_decode($text, true);

        return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : null;
    }
}
