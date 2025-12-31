<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.smartql.url', 'http://smartql:8000');
    }

    public function ask(
        string $question,
        int $userId,
        bool $execute = true,
        ?string $formatHint = null,
        bool $generateResponse = true,
        ?string $language = null
    ): array {
        try {
            $payload = [
                'question' => $question,
                'execute' => $execute,
                'generate_response' => $generateResponse,
                'context' => [
                    'user_id' => $userId,
                    'language' => $language ?? app()->getLocale(),
                ],
            ];

            if ($formatHint !== null) {
                $payload['format_hint'] = $formatHint;
            }

            $response = Http::timeout(60)->post("{$this->baseUrl}/ask", $payload);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            Log::warning('SmartQL request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'success' => false,
                'error' => __('Failed to process your question. Please try again.'),
            ];
        } catch (\Exception $e) {
            Log::error('SmartQL service error', [
                'message' => $e->getMessage(),
                'question' => $question,
            ]);

            return [
                'success' => false,
                'error' => __('AI service is currently unavailable. Please try again later.'),
            ];
        }
    }

    public function healthCheck(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/health");

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
