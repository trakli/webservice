<?php

namespace Tests\Unit;

use App\Ai\Billing\MeteredAgentEngine;
use App\Ai\Billing\MeteredPrism;
use App\Ai\Billing\ModelCallGate;
use App\Models\User;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Prism\Prism\PrismManager;
use Whilesmart\AgentMetrics\Facades\TokenMeter;
use Whilesmart\AgentMetrics\Models\TokenUsage;
use Whilesmart\Agents\Contracts\Tool;
use Whilesmart\Agents\Engines\Prism\PrismToolAdapter;
use Whilesmart\Agents\Enums\StreamEventType;
use Whilesmart\Agents\ValueObjects\AgentRequest;
use Whilesmart\Agents\ValueObjects\AgentStreamEvent;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;
use Whilesmart\Entitlements\Contracts\Entitlements;
use Whilesmart\Entitlements\Support\AllowAllEntitlements;

class MeteredAgentStreamingTest extends MockeryTestCase
{
    private Application $app;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Repository([
            'prism' => ['request_timeout' => 30, 'providers' => ['anthropic' => [
                'api_key' => 'your-api-key-here', 'url' => 'https://api.anthropic.com/v1', 'version' => '2023-06-01',
            ]]],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
            'entitlements' => ['usage_lock_store' => 'array'],
            'user-authentication' => ['response_formatter' => \Whilesmart\UserAuthentication\ResponseFormatters\DefaultResponseFormatter::class],
        ]));
        $auth = Mockery::mock(\Illuminate\Contracts\Auth\Guard::class);
        $auth->shouldReceive('user')->andReturnNull();
        $this->app->instance('auth', $auth);
        $responses = Mockery::mock(\Illuminate\Contracts\Routing\ResponseFactory::class);
        $responses->shouldReceive('json')->andReturnUsing(fn ($data, $status) => new \Illuminate\Http\JsonResponse($data, $status));
        $this->app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, $responses);
        $this->app->singleton('cache', fn () => new CacheManager($this->app));
        $this->app->singleton(Factory::class, fn () => new Factory());
        $this->app->singleton(PrismManager::class, fn () => new PrismManager($this->app));
        $this->app->singleton('prism', MeteredPrism::class);
        $this->app->singleton(ModelCallGate::class);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        Http::preventStrayRequests();
        Http::globalRequestMiddleware(fn ($request) => app(ModelCallGate::class)->beforeProviderRequest($request));
        Http::globalResponseMiddleware(fn ($response) => app(ModelCallGate::class)->afterProviderResponse($response));
        $this->owner = new User();
        $this->owner->setRawAttributes(['id' => 17]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        $this->app->flush();
        parent::tearDown();
    }

    public function test_metered_text_is_forwarded_before_the_provider_stream_ends(): void
    {
        $this->bindMeter(115);
        $body = $this->fixture('stream-basic-text-1.sse');
        Http::fake(['api.anthropic.com/*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);
        $deltas = [];
        $key = 'ai-owner:'.hash('sha256', $this->owner->getMorphClass().':'.$this->owner->getKey());
        $result = $this->engine()->stream($this->request(), function (AgentStreamEvent $event) use (&$deltas, $body, $key) {
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
        $this->assertSame(115, array_sum($result->usage));
        $lock = Cache::store('array')->lock($key);
        $this->assertTrue($lock->get());
        $lock->release();
        $this->assertNull(app(ModelCallGate::class)->owner());
        Http::assertSentCount(1);
    }

    public function test_metered_thinking_events_reach_the_caller(): void
    {
        $this->bindMeter(502);
        Http::fake(['api.anthropic.com/*' => Http::response($this->fixture('stream-with-extended-thinking-1.sse'))]);
        $types = [];
        $result = $this->engine()->stream($this->request(), function (AgentStreamEvent $event) use (&$types) {
            $types[] = $event->type;
        });
        $this->assertTrue($result->ok, $result->error ?? '');
        $this->assertContains(StreamEventType::Thinking, $types);
        $this->assertContains(StreamEventType::TextDelta, $types);
        $this->assertContains(StreamEventType::StepFinish, $types);
    }

    public function test_metered_tool_progress_is_forwarded_before_tool_execution(): void
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
        $result = $this->engine()->stream($this->request([$tool]), function (AgentStreamEvent $event) use (&$progress) {
            if ($event->type === StreamEventType::ToolCall) {
                $progress = true;
                $this->assertSame('search', $event->toolName);
                $this->assertSame('Detroit Tigers baseball game today time schedule', $event->arguments['query']);
            }
        });
        $this->assertTrue($result->ok, $result->error ?? '');
        $this->assertTrue($progress);
    }

    public function test_exhausted_allowance_prevents_a_provider_request(): void
    {
        $meter = Mockery::mock(Entitlements::class);
        $meter->shouldReceive('allows')->with($this->owner, 'ai')->andReturn(true);
        $meter->shouldReceive('remaining')->with($this->owner, 'ai_tokens')->andReturn(0);
        $meter->shouldNotReceive('consume');
        $this->app->instance(Entitlements::class, $meter);
        TokenMeter::shouldReceive('record')->never();
        Http::fake();
        $events = [];
        $result = $this->engine()->stream($this->request(), function ($event) use (&$events) {
            $events[] = $event;
        });
        $this->assertFalse($result->ok);
        $this->assertSame([], $events);
        Http::assertNothingSent();
    }

    public function test_self_hosted_streaming_still_forwards_multiple_deltas(): void
    {
        $this->app->instance(Entitlements::class, new AllowAllEntitlements());
        TokenMeter::shouldReceive('record')->once()->andReturn(new TokenUsage());
        Http::fake(['api.anthropic.com/*' => Http::response($this->fixture('stream-basic-text-1.sse'))]);
        $deltas = [];
        $result = $this->engine()->stream($this->request(), function (AgentStreamEvent $event) use (&$deltas) {
            if ($event->type === StreamEventType::TextDelta) {
                $deltas[] = $event->text;
            }
        });
        $this->assertTrue($result->ok, $result->error ?? '');
        $this->assertGreaterThan(1, count($deltas));
    }

    private function bindMeter(int $tokens): void
    {
        $meter = Mockery::mock(Entitlements::class);
        $meter->shouldReceive('allows')->with($this->owner, 'ai')->andReturn(true);
        $meter->shouldReceive('remaining')->with($this->owner, 'ai_tokens')->andReturn(100000);
        $meter->shouldReceive('consume')->once()->with($this->owner, 'ai_tokens', $tokens);
        $this->app->instance(Entitlements::class, $meter);
        TokenMeter::shouldReceive('record')->once()->withArgs(function (...$arguments) use ($tokens) {
            $this->assertSame($this->owner, $arguments[0]);
            $this->assertSame($tokens, $arguments[3]['total_tokens']);
            return true;
        })->andReturn(new TokenUsage());
    }

    private function fixture(string $name): \Psr\Http\Message\StreamInterface
    {
        return Utils::streamFor(file_get_contents(__DIR__.'/../Fixtures/prism/'.$name));
    }

    private function engine(): MeteredAgentEngine
    {
        return new MeteredAgentEngine(new PrismToolAdapter());
    }

    private function request(array $tools = []): AgentRequest
    {
        return new AgentRequest(
            systemPrompt: 'Answer the question.',
            input: 'Question',
            tools: $tools,
            context: ToolContext::forUser($this->owner),
            provider: 'anthropic',
            model: 'test-model',
            temperature: null,
            maxSteps: 1,
        );
    }
}
