<?php

namespace App\Ai\Billing;

use Whilesmart\Agents\ValueObjects\AgentRequest;
use Whilesmart\Agents\ValueObjects\AgentResult;

class MeteredAgentEngine extends \Whilesmart\Agents\Engines\Prism\PrismEngine
{
    public function run(AgentRequest $request): AgentResult
    {
        return app(ModelCallGate::class)->withOwner($request->context->user, fn () => parent::run($request));
    }

    public function stream(AgentRequest $request, callable $onEvent): AgentResult
    {
        if (app(\Whilesmart\Entitlements\Contracts\Entitlements::class) instanceof \Whilesmart\Entitlements\Support\AllowAllEntitlements) {
            return app(ModelCallGate::class)->withOwner($request->context->user, fn () => parent::stream($request, $onEvent));
        }

        $result = $this->run($request);

        if ($result->text !== '') {
            $onEvent(\Whilesmart\Agents\ValueObjects\AgentStreamEvent::textDelta($result->text));
        }

        for ($step = 0; $step < $result->steps; $step++) {
            $onEvent(\Whilesmart\Agents\ValueObjects\AgentStreamEvent::stepFinish());
        }

        return $result;
    }
}
