<?php

namespace App\Ai\Billing;

use Whilesmart\Entitlements\Support\AllowAllEntitlements;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Prism\Prism\ValueObjects\Usage;
use Whilesmart\AgentMetrics\Facades\TokenMeter;
use Whilesmart\Entitlements\Contracts\Entitlements;

class ModelCallGate
{
    private ?Model $owner = null;

    private array $heldLocks = [];

    private ?array $activeCall = null;

    public function withOwner(?Model $owner, callable $call): mixed
    {
        $previous = $this->owner;
        $this->owner = $owner;

        try {
            return $call();
        } finally {
            $this->owner = $previous;
        }
    }

    public function owner(): ?Model
    {
        return $this->owner ?? auth()->user();
    }

    public function withProvider(?Model $owner, string $provider, string $model, callable $call, array $metadata = []): mixed
    {
        if (! in_array($provider, ['openai', 'groq', 'anthropic', 'gemini'], true)) {
            throw new RuntimeException('This provider has no verified usage accounting adapter.');
        }

        $previous = $this->activeCall;
        $this->activeCall = ['owner' => $owner, 'provider' => $provider, 'model' => $model, 'metadata' => $metadata];
        try {
            return $call();
        } finally {
            $this->activeCall = $previous;
        }
    }

    public function beforeProviderRequest($request)
    {
        if ($this->activeCall !== null) {
            $owner = $this->activeCall['owner'];
            $this->assertAllowed($owner);
            $callId = $this->activeCall['metadata']['call_id'] ?? null;
            if ($owner !== null && $callId !== null && $owner->tokenUsages()->where('metadata->call_id', $callId)->exists()) {
                throw new RuntimeException('This model call already completed. Reuse its recorded result.');
            }
        }

        return $request;
    }

    public function afterProviderResponse($response)
    {
        if ($this->activeCall === null || $response->getStatusCode() >= 400) {
            return $response;
        }

        $body = $response->getBody();
        $data = json_decode((string) $body, true);
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $provider = $this->activeCall['provider'];
        $usage = match ($provider) {
            'openai' => new Usage((int) data_get($data, 'usage.input_tokens', 0), (int) data_get($data, 'usage.output_tokens', 0)),
            'groq' => new Usage((int) data_get($data, 'usage.prompt_tokens', 0), (int) data_get($data, 'usage.completion_tokens', 0)),
            'anthropic' => new Usage(
                (int) data_get($data, 'usage.input_tokens', 0),
                (int) data_get($data, 'usage.output_tokens', 0),
                (int) data_get($data, 'usage.cache_creation_input_tokens', 0),
                (int) data_get($data, 'usage.cache_read_input_tokens', 0),
            ),
            'gemini' => new Usage(
                (int) data_get($data, 'usageMetadata.promptTokenCount', 0),
                (int) data_get($data, 'usageMetadata.candidatesTokenCount', 0),
                thoughtTokens: (int) data_get($data, 'usageMetadata.thoughtsTokenCount', 0),
            ),
            default => throw new RuntimeException('This provider has no verified usage accounting adapter.'),
        };
        $required = match ($provider) {
            'openai', 'anthropic' => ['usage.input_tokens', 'usage.output_tokens'],
            'groq' => ['usage.prompt_tokens', 'usage.completion_tokens'],
            'gemini' => ['usageMetadata.promptTokenCount'],
        };
        foreach ($required as $key) {
            $value = data_get($data, $key);
            if (! is_int($value) || $value < 0) {
                $this->blockPendingAccounting($this->activeCall['owner'], $this->activeCall['metadata']['call_id'] ?? null);
                throw new RuntimeException('Provider usage requires reconciliation before another model call.');
            }
        }
        $metadata = $this->activeCall['metadata'];
        $this->record($this->activeCall['owner'], $usage, $provider, $this->activeCall['model'], $metadata);

        return $response;
    }

    public function serialized(?Model $owner, callable $call): mixed
    {
        if ($owner === null || app(Entitlements::class) instanceof AllowAllEntitlements) {
            return $call();
        }

        $key = sprintf('ai-owner:%s', hash('sha256', sprintf('%s:%s', $owner->getMorphClass(), $owner->getKey())));
        if (isset($this->heldLocks[$key])) {
            return $call();
        }

        return Cache::store(config('entitlements.usage_lock_store', 'redis'))
            ->lock($key, 900)->block(30, function () use ($key, $call) {
                $this->heldLocks[$key] = true;
                try {
                    return $call();
                } finally {
                    unset($this->heldLocks[$key]);
                }
            });
    }

    public function assertAllowed(?Model $owner): void
    {
        $entitlements = app(Entitlements::class);
        if (
            ! $entitlements instanceof AllowAllEntitlements && $owner !== null
            && DB::table('model_usage_blocks')->where('owner_type', $owner->getMorphClass())
                ->where('owner_id', $owner->getKey())->exists()
        ) {
            throw new HttpResponseException(app(config('user-authentication.response_formatter'))->failure(
                'AI access is paused until provider usage has been reconciled.',
                402,
                ['feature' => 'ai', 'reason' => 'accounting_pending'],
            ));
        }

        if (! $entitlements->allows($owner, 'ai') || $entitlements->remaining($owner, 'ai_tokens') <= 0) {
            throw new HttpResponseException(app(config('user-authentication.response_formatter'))->failure(
                'Paid AI access or the monthly token allowance is unavailable.',
                402,
                ['feature' => 'ai'],
            ));
        }
    }

    private function blockPendingAccounting(?Model $owner, ?string $callId): void
    {
        if ($owner === null) {
            throw new RuntimeException('The model call has no accountable owner.');
        }

        DB::table('model_usage_blocks')->upsert([
            'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey(),
            'reason' => 'provider_usage_missing', 'call_id' => $callId, 'created_at' => now('UTC'),
        ], ['owner_type', 'owner_id'], ['reason', 'call_id', 'created_at']);
    }

    public static function totalTokens(Usage $usage, string $provider): int
    {
        $total = $usage->promptTokens + $usage->completionTokens;

        if (in_array($provider, ['anthropic', 'openai'], true)) {
            $total += ($usage->cacheWriteInputTokens ?? 0) + ($usage->cacheReadInputTokens ?? 0);
        }

        if ($provider === 'gemini') {
            $total += $usage->thoughtTokens ?? 0;
        }

        return $total;
    }

    public function record(?Model $owner, Usage $usage, string $provider, string $model, array $metadata = []): void
    {
        if ($owner === null) {
            return;
        }

        if (isset($metadata['call_id']) && $owner->tokenUsages()->where('metadata->call_id', $metadata['call_id'])->exists()) {
            return;
        }

        $values = $usage->toArray();
        $values['total_tokens'] = self::totalTokens($usage, $provider);
        TokenMeter::record(
            owner: $owner,
            provider: $provider,
            model: $model,
            usage: $values,
            operation: $metadata === [] ? 'model.call' : 'smartql.model',
            metadata: $metadata
        );
        app(Entitlements::class)->consume($owner, 'ai_tokens', $values['total_tokens']);
    }
}
