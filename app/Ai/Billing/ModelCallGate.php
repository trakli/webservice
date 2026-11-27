<?php

namespace App\Ai\Billing;

use Whilesmart\Entitlements\Support\AllowAllEntitlements;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Whilesmart\AgentMetrics\Facades\TokenMeter;
use Whilesmart\Entitlements\Contracts\Entitlements;

class ModelCallGate
{
    public const METERED_PROVIDERS = ['openai', 'groq', 'anthropic', 'gemini'];

    private array $heldLocks = [];

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
        if (! $entitlements->allows($owner, 'ai') || $entitlements->remaining($owner, 'ai_tokens') <= 0) {
            throw new HttpResponseException(app(config('user-authentication.response_formatter'))->failure(
                'Paid AI access or the monthly token allowance is unavailable.',
                402,
                ['feature' => 'ai'],
            ));
        }
    }

    public function recordRemoteUsage(?Model $owner, mixed $usage): void
    {
        foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $key) {
            if (! is_array($usage) || ! isset($usage[$key]) || ! is_int($usage[$key]) || $usage[$key] < 0) {
                return;
            }
        }
        if ($owner !== null) {
            TokenMeter::record(
                owner: $owner,
                provider: 'smartql',
                model: 'smartql',
                usage: [
                    'prompt_tokens' => $usage['input_tokens'],
                    'completion_tokens' => $usage['output_tokens'],
                    'total_tokens' => $usage['total_tokens'],
                ],
                operation: 'smartql.request',
            );
            app(Entitlements::class)->consume($owner, 'ai_tokens', $usage['total_tokens']);
        }
    }

    /**
     * @param array<string, int> $usage A run's usage as the agents package reports it.
     */
    public static function totalTokens(array $usage, string $provider): int
    {
        $total = ($usage['prompt_tokens'] ?? 0) + ($usage['completion_tokens'] ?? 0);

        if (in_array($provider, ['anthropic', 'openai'], true)) {
            $total += ($usage['cache_write_input_tokens'] ?? 0) + ($usage['cache_read_input_tokens'] ?? 0);
        }

        if ($provider === 'gemini') {
            $total += $usage['thought_tokens'] ?? 0;
        }

        return $total;
    }

    /**
     * @param array<string, int> $usage A run's usage as the agents package reports it.
     */
    public function record(?Model $owner, array $usage, string $provider, string $model, array $metadata = []): void
    {
        if ($owner === null) {
            return;
        }

        if (isset($metadata['call_id']) && $owner->tokenUsages()->where('metadata->call_id', $metadata['call_id'])->exists()) {
            return;
        }

        $values = $usage;
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
