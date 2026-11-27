<?php

namespace Tests\Feature;

use App\Ai\ModelCall;
use App\Models\User;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;
use Whilesmart\AgentMetrics\Facades\TokenMeter;
use Whilesmart\AgentMetrics\Models\TokenUsage;
use Whilesmart\Agents\Contracts\Tool;
use Whilesmart\Agents\Enums\StreamEventType;
use Whilesmart\Agents\Facades\Agents;
use Whilesmart\Agents\ValueObjects\AgentResult;
use Whilesmart\Agents\ValueObjects\AgentStreamEvent;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;
use Whilesmart\Entitlements\Contracts\Entitlements;
use Whilesmart\Entitlements\Support\AllowAllEntitlements;

class MeteredRunsTest extends TestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'prism.providers.anthropic.api_key' => 'your-api-key-here',
            'prism.providers.anthropic.url' => 'https://api.anthropic.com/v1',
            'entitlements.usage_lock_store' => 'array',
        ]);
        Http::preventStrayRequests();
        $this->owner = new User;
        $this->owner->setRawAttributes(['id' => 17]);
    }

    public function test_streamed_text_reaches_the_caller_while_the_owner_lock_is_held(): void
    {
        $this->bindMeter(115);
        $body = $this->fixture('stream-basic-text-1.sse');
        Http::fake(['api.anthropic.com/*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);
        $deltas = [];
        $key = 'ai-owner:'.hash('sha256', $this->owner->getMorphClass().':'.$this->owner->getKey());

        $result = $this->stream(function (AgentStreamEvent $event) use (&$deltas, $body, $key) {
            if ($event->type === StreamEventType::TextDelta) {
                if ($deltas === []) {
                    $this->assertFalse($body->eof());
                    $this->assertFalse(Cache::store('array')->lock($key)->get());
                }
                $deltas[] = $event->text;
            }
        });

        $this->assertTrue($result->ok, $result->error ?? '');
        $this->assertGreaterThan(1, count($deltas));
        $this->assertSame($result->text, trim(implode('', $deltas)));
        $lock = Cache::store('array')->lock($key);
        $this->assertTrue($lock->get());
        $lock->release();
        Http::assertSentCount(1);
    }

    public function test_streamed_thinking_reaches_the_caller(): void
    {
        $this->bindMeter(502);
        Http::fake(['api.anthropic.com/*' => Http::response($this->fixture('stream-with-extended-thinking-1.sse'))]);
        $types = [];

        $result = $this->stream(function (AgentStreamEvent $event) use (&$types) {
            $types[] = $event->type;
        });

        $this->assertTrue($result->ok, $result->error ?? '');
        $this->assertContains(StreamEventType::Thinking, $types);
        $this->assertContains(StreamEventType::TextDelta, $types);
    }

    public function test_tool_progress_reaches_the_caller_before_the_tool_runs(): void
    {
        $this->bindMeter(561);
        Http::fake(['api.anthropic.com/*' => Http::response($this->fixture('stream-with-tools-1.sse'))]);
        $progress = false;
        $tool = Mockery::mock(Tool::class);
        $tool->shouldReceive('name')->andReturn('search');
        $tool->shouldReceive('description')->andReturn('Search');
        $tool->shouldReceive('parameters')->andReturn([ParameterSpec::string('query', 'Query')]);
        $tool->shouldReceive('authorize')->andReturn(true);
        $tool->shouldReceive('handle')->once()->andReturnUsing(function () use (&$progress) {
            $this->assertTrue($progress);

            return 'Search result';
        });
        Agents::registerTool($tool);

        $result = $this->stream(function (AgentStreamEvent $event) use (&$progress) {
            if ($event->type === StreamEventType::ToolCall) {
                $progress = true;
                $this->assertSame('search', $event->toolName);
            }
        }, ['search']);

        $this->assertTrue($result->ok, $result->error ?? '');
        $this->assertTrue($progress);
    }

    public function test_an_exhausted_allowance_stops_a_run_before_the_provider_is_called(): void
    {
        $meter = Mockery::mock(Entitlements::class);
        $meter->shouldReceive('allows')->with($this->owner, 'ai')->andReturn(true);
        $meter->shouldReceive('remaining')->with($this->owner, 'ai_tokens')->andReturn(0);
        $meter->shouldNotReceive('consume');
        $this->app->instance(Entitlements::class, $meter);
        TokenMeter::shouldReceive('record')->never();
        Http::fake();
        $events = [];

        $result = $this->stream(function ($event) use (&$events) {
            $events[] = $event;
        });

        $this->assertFalse($result->ok);
        $this->assertSame([], $events);
        $this->expectException(\RuntimeException::class);
        try {
            ModelCall::text('router.title', $this->owner, 'Title it.', 'Question', 'anthropic', 'test-model', 0);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_provider_without_usage_accounting_is_refused(): void
    {
        $this->bindAllowance();
        TokenMeter::shouldReceive('record')->never();
        $fake = Prism::fake([TextResponseFake::make()->withText('never')]);

        $this->expectExceptionMessage('This provider has no verified usage accounting adapter.');
        try {
            ModelCall::text('router.title', $this->owner, 'Title it.', 'Question', 'ollama', 'llama', 0);
        } finally {
            $fake->assertCallCount(0);
        }
    }

    public function test_a_single_model_call_is_capped_and_records_its_usage(): void
    {
        $this->bindMeter(625);
        $fake = Prism::fake([
            TextResponseFake::make()->withText('Budget review')->withUsage(new Usage(100, 25, 200, 300)),
        ]);

        $text = ModelCall::text('router.title', $this->owner, 'Title it.', 'Question', 'anthropic', 'test-model', 0.3);

        $this->assertSame('Budget review', $text);
        $fake->assertRequest(fn (array $requests) => $this->assertSame(4096, $requests[0]->maxTokens()));
    }

    public function test_self_hosted_runs_are_recorded_without_a_limit(): void
    {
        $this->app->instance(Entitlements::class, new AllowAllEntitlements);
        TokenMeter::shouldReceive('record')->once()->andReturn(new TokenUsage);
        Http::fake(['api.anthropic.com/*' => Http::response($this->fixture('stream-basic-text-1.sse'))]);
        $deltas = [];

        $result = $this->stream(function (AgentStreamEvent $event) use (&$deltas) {
            if ($event->type === StreamEventType::TextDelta) {
                $deltas[] = $event->text;
            }
        });

        $this->assertTrue($result->ok, $result->error ?? '');
        $this->assertGreaterThan(1, count($deltas));
    }

    private function stream(callable $onEvent, array $tools = []): AgentResult
    {
        Agents::registerHarness('metered-test', [
            'prompt' => 'Answer the question.',
            'tools' => $tools,
            'provider' => 'anthropic',
            'model' => 'test-model',
            'max_steps' => 1,
        ]);

        return Agents::stream('metered-test', 'Question', ToolContext::forUser($this->owner), $onEvent);
    }

    private function bindAllowance(): Entitlements
    {
        $meter = Mockery::mock(Entitlements::class);
        $meter->shouldReceive('allows')->with($this->owner, 'ai')->andReturn(true);
        $meter->shouldReceive('remaining')->with($this->owner, 'ai_tokens')->andReturn(100000);
        $this->app->instance(Entitlements::class, $meter);

        return $meter;
    }

    private function bindMeter(int $tokens): void
    {
        $this->bindAllowance()->shouldReceive('consume')->once()->with($this->owner, 'ai_tokens', $tokens);
        TokenMeter::shouldReceive('record')->once()->withArgs(function (...$arguments) use ($tokens) {
            $this->assertSame($this->owner, $arguments[0]);
            $this->assertSame($tokens, $arguments[3]['total_tokens']);

            return true;
        })->andReturn(new TokenUsage);
    }

    private function fixture(string $name): StreamInterface
    {
        return Utils::streamFor(file_get_contents(__DIR__.'/../Fixtures/prism/'.$name));
    }
}
