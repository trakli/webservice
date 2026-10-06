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

            $response = $this->sendRequest($owner, $payload);

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
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
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

    private function sendRequest(?\App\Models\User $owner, array $payload): \Illuminate\Http\Client\Response
    {
        $paidHost = ! app(\Whilesmart\Entitlements\Contracts\Entitlements::class) instanceof \Whilesmart\Entitlements\Support\AllowAllEntitlements;
        $http = Http::timeout(240);
        if (filled(config('services.smartql.api_key'))) {
            $http->withHeaders(['X-API-Key' => config('services.smartql.api_key')]);
        }
        $gate = app(\App\Ai\Billing\ModelCallGate::class);
        return $gate->serialized($owner, function () use ($gate, $owner, $paidHost, $http, $payload) {
            if ($paidHost) {
                $gate->assertAllowed($owner);
            }
            $response = $http->post("{$this->baseUrl}/ask", $payload);
            $usage = $response->json('usage') ?? $response->json('detail.usage');
            if ($paidHost || (is_array($usage) && ($usage['complete'] ?? false) === true)) {
                $gate->recordRemoteUsage($owner, $usage);
            }

            return $response;
        });
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
