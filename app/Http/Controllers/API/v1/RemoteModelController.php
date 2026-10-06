<?php

namespace App\Http\Controllers\API\v1;

use App\Ai\Billing\ModelCallGate;
use App\Ai\Billing\RemoteModelGrant;
use App\Http\Controllers\API\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Whilesmart\AgentMetrics\Models\TokenUsage;

class RemoteModelController extends ApiController
{
    public function store(Request $request, RemoteModelGrant $grants, ModelCallGate $gate): JsonResponse
    {
        try {
            [$owner, $requestId] = $grants->resolve($request->bearerToken() ?? '');
        } catch (\Throwable $exception) {
            return $this->failure('Invalid or expired model owner grant.', 401);
        }

        $input = $request->validate([
            'call_id' => ['required', 'uuid'],
            'messages' => ['required', 'array', 'min:1', 'max:2'],
            'messages.*.role' => ['required', 'in:system,user'],
            'messages.*.content' => ['required', 'string', 'max:64000'],
            'temperature' => ['sometimes', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['required', 'integer', 'min:1', 'max:4096'],
        ]);
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        $identity = sprintf('%s:%s', $requestId, $input['call_id']);

        return $gate->serialized($owner, function () use ($owner, $identity, $hash, $input, $gate): JsonResponse {
            $existing = TokenUsage::query()->where('owner_type', $owner->getMorphClass())
                ->where('owner_id', $owner->getKey())->where('operation', 'smartql.model')
                ->where('metadata->call_id', $identity)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->metadata['payload_hash'], $hash)) {
                    return $this->failure('The model call identifier was already used for another request.', 409);
                }

                if (! isset($existing->metadata['text'])) {
                    return $this->failure('The completed model call has no recoverable response.', 409);
                }

                return $this->success(['text' => $existing->metadata['text']]);
            }

            $gate->assertAllowed($owner);
            $provider = config('services.llm.provider');
            $model = config('services.llm.model');
            $pending = (new Prism())->text()->using($provider, $model)
                ->withMaxSteps(1)->withMaxTokens($input['max_tokens'])
                ->usingTemperature($input['temperature'] ?? 0)
                ->withClientOptions(['timeout' => 120]);
            $messages = [];
            foreach ($input['messages'] as $message) {
                if ($message['role'] === 'system') {
                    $pending->withSystemPrompt($message['content']);
                } else {
                    $messages[] = new UserMessage($message['content']);
                }
            }
            if ($messages === []) {
                return $this->failure('A user prompt is required.', 422);
            }
            $response = $gate->withProvider(
                $owner,
                $provider,
                $model,
                fn () => $pending->withMessages($messages)->asText(),
                ['call_id' => $identity, 'payload_hash' => $hash]
            );
            $record = TokenUsage::query()->where('owner_type', $owner->getMorphClass())
                ->where('owner_id', $owner->getKey())->where('operation', 'smartql.model')
                ->where('metadata->call_id', $identity)->firstOrFail();
            $record->update(['metadata' => [...$record->metadata, 'text' => $response->text]]);

            return $this->success(['text' => $response->text]);
        });
    }
}
