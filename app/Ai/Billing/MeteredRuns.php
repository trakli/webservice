<?php

namespace App\Ai\Billing;

use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Whilesmart\Agents\Contracts\RunGuard;
use Whilesmart\Agents\ValueObjects\AgentRequest;
use Whilesmart\Agents\ValueObjects\AgentResult;
use Whilesmart\Entitlements\Contracts\Entitlements;
use Whilesmart\Entitlements\Support\AllowAllEntitlements;

class MeteredRuns implements RunGuard
{
    private const MAX_TOKENS = 4096;

    public function __construct(private ModelCallGate $gate)
    {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handle(string $name, AgentRequest $request, Closure $next): AgentResult
    {
        $owner = $request->context->user;

        if (app(Entitlements::class) instanceof AllowAllEntitlements) {
            return $this->recorded($request, $next($request));
        }

        if (! in_array($request->provider, ModelCallGate::METERED_PROVIDERS, true)) {
            return AgentResult::failed('This provider has no verified usage accounting adapter.');
        }

        return $this->gate->serialized($owner, function () use ($owner, $request, $next) {
            try {
                $this->gate->assertAllowed($owner);
            } catch (HttpResponseException) {
                return AgentResult::failed('Paid AI access or the monthly token allowance is unavailable.');
            }

            $request = $request->with(maxTokens: min($request->maxTokens ?? self::MAX_TOKENS, self::MAX_TOKENS));

            return $this->recorded($request, $next($request));
        });
    }

    private function recorded(AgentRequest $request, AgentResult $result): AgentResult
    {
        $usage = $result->usage;
        if (array_sum($usage) === 0) {
            return $result;
        }
        $this->gate->record($request->context->user, $usage, (string) $request->provider, (string) $request->model);

        return $result;
    }
}
