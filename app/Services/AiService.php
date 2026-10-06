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
        ?string $language = null,
        ?string $role = null
    ): array {
        $paidHost = ! app(\Whilesmart\Entitlements\Contracts\Entitlements::class) instanceof \Whilesmart\Entitlements\Support\AllowAllEntitlements;
        $owner = \App\Models\User::find($userId);
        if ($paidHost) {
            if ($owner === null || blank(config('services.smartql.api_key'))) {
                return ['success' => false, 'error' => __('Authenticated remote AI is unavailable.')];
            }
            app(\App\Ai\Billing\ModelCallGate::class)->assertAllowed($owner);
        }

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

            if ($role !== null) {
                $payload['context']['role'] = $role;
            }

            if ($formatHint !== null) {
                $payload['format_hint'] = $formatHint;
            }

            if ($paidHost) {
                $payload['owner_grant'] = app(\App\Ai\Billing\RemoteModelGrant::class)->issue($owner);
            }
            $http = Http::timeout(240);
            if (filled(config('services.smartql.api_key'))) {
                $http->withHeaders(['X-API-Key' => config('services.smartql.api_key')]);
            }
            $response = $http->post("{$this->baseUrl}/ask", $payload);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            Log::warning('SmartQL request failed', [
                'status' => $response->status(),
            ]);

            return [
                'success' => false,
                'error' => __('Failed to process your question. Please try again.'),
            ];
        } catch (\Exception $e) {
            Log::error('SmartQL service error', [
                'message' => $e->getMessage(),
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
