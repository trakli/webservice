<?php

namespace App\Ai\Billing;

use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Concerns\CallsTools;
use Whilesmart\Entitlements\Contracts\Entitlements;
use Whilesmart\Entitlements\Support\AllowAllEntitlements;
use Prism\Prism\Text\Step;
use Prism\Prism\Text\ResponseBuilder;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use RuntimeException;
use LogicException;
use Generator;
use Illuminate\Database\Eloquent\Model;
use Prism\Prism\Text\Response;

class MeteredPendingRequest extends PendingRequest
{
    use CallsTools;

    public function __construct(private ?Model $owner)
    {
    }

    public function asText(?callable $callback = null): Response
    {
        if (app(Entitlements::class) instanceof AllowAllEntitlements) {
            $response = parent::asText($callback);
            app(ModelCallGate::class)->record($this->owner, $response->usage, $this->providerKey(), $this->model());

            return $response;
        }

        if (! in_array($this->providerKey(), ['openai', 'groq', 'anthropic', 'gemini'], true)) {
            throw new RuntimeException('This provider has no verified usage accounting adapter.');
        }

        $maximum = max(1, $this->maxSteps);
        $this->maxSteps = 1;
        $steps = collect();
        $response = null;

        for ($step = 0; $step < $maximum; $step++) {
            $call = function () {
                $gate = app(ModelCallGate::class);
                $gate->assertAllowed($this->owner);
                $this->maxTokens = min($this->maxTokens ?? 4096, 4096);
                $tools = $this->tools;
                $this->tools = array_map(fn ($tool) => (clone $tool)->using(fn () => 'pending')->concurrent(false), $tools);
                try {
                    $response = $gate->withProvider($this->owner, $this->providerKey(), $this->model(), fn () => parent::asText());
                } finally {
                    $this->tools = $tools;
                }

                return $response;
            };

            $response = app(ModelCallGate::class)->serialized($this->owner, $call);
            $tools = $this->tools;
            if ($response->toolCalls !== []) {
                $results = $this->callTools(array_map(fn ($tool) => (clone $tool)->concurrent(false), $tools), $response->toolCalls);
                $responseStep = $response->steps->last();
                $responseStep = new Step(
                    text: $responseStep->text,
                    finishReason: $responseStep->finishReason,
                    toolCalls: $responseStep->toolCalls,
                    toolResults: $results,
                    providerToolCalls: $responseStep->providerToolCalls,
                    usage: $responseStep->usage,
                    meta: $responseStep->meta,
                    messages: $responseStep->messages,
                    systemPrompts: $responseStep->systemPrompts,
                    additionalContent: $responseStep->additionalContent,
                    raw: $responseStep->raw,
                );
                $builder = new ResponseBuilder();
                $builder->addStep($responseStep);
                $response = $builder->toResponse();
            }

            $steps = $steps->concat($response->steps);

            if ($response->finishReason !== FinishReason::ToolCalls) {
                break;
            }

            $this->toolChoice = null;
            $this->prompt = null;
            $this->messages = $response->messages->all();
            $this->messages[] = new ToolResultMessage($response->toolResults);
        }

        $builder = new ResponseBuilder();
        foreach ($steps as $step) {
            $builder->addStep($step);
        }
        $response = $builder->toResponse();

        if ($callback !== null) {
            $callback($this, $response);
        }

        return $response;
    }

    public function asStream(): Generator
    {
        if (! app(Entitlements::class) instanceof AllowAllEntitlements) {
            throw new LogicException('Metered model calls use the agent engine progress stream.');
        }

        foreach (parent::asStream() as $event) {
            if ($event instanceof StreamEndEvent && $event->usage !== null) {
                app(ModelCallGate::class)->record($this->owner, $event->usage, $this->providerKey(), $this->model());
            }
            yield $event;
        }
    }
}
