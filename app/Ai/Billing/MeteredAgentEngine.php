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
        $gate = app(ModelCallGate::class);
        $owner = $request->context->user;

        return $gate->serialized($owner, fn () => $gate->withOwner(
            $owner,
            fn () => parent::stream($request, $onEvent),
        ));
    }
}
