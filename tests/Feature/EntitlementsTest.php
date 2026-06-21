<?php

namespace Tests\Feature;

use App\Contracts\Entitlements;
use App\Jobs\ProcessChatMessageJob;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\AgentRunner;
use App\Services\AiRouter;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EntitlementsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_default_binding_allows_unlimited_wallets(): void
    {
        $resolved = $this->app->make(Entitlements::class);

        $this->assertNull($resolved->limit($this->user, 'max_wallets'));
        $this->assertTrue($resolved->allows($this->user, 'plaid'));
        $this->assertEquals(INF, $resolved->remaining($this->user, 'ai_tokens'));
    }

    public function test_wallet_create_blocked_when_over_limit(): void
    {
        $this->bindLimit('max_wallets', 0);

        $this->actingAs($this->user)
            ->postJson('/api/v1/wallets', [
                'name' => 'My Wallet',
                'type' => 'bank',
                'currency' => 'XAF',
                'balance' => 0,
            ])
            ->assertStatus(403);

        $this->assertEquals(0, $this->user->wallets()->count());
    }

    public function test_category_create_blocked_when_over_limit(): void
    {
        $this->bindLimit('max_categories', 0);

        $this->actingAs($this->user)
            ->postJson('/api/v1/categories', [
                'type' => 'expense',
                'name' => 'Groceries',
            ])
            ->assertStatus(403);

        $this->assertEquals(0, $this->user->categories()->count());
    }

    public function test_ai_turn_blocked_when_token_allowance_exhausted(): void
    {
        $fake = Mockery::mock(Entitlements::class);
        $fake->shouldReceive('remaining')->with(Mockery::any(), 'ai_tokens')->andReturn(0);
        $fake->shouldNotReceive('consume');
        $this->app->instance(Entitlements::class, $fake);

        [$assistant] = $this->makeTurn('log 20 for coffee');

        (new ProcessChatMessageJob($assistant))->handle(
            Mockery::mock(AiService::class),
            Mockery::mock(AiRouter::class),
            Mockery::mock(AgentRunner::class),
        );

        $assistant->refresh();
        $this->assertEquals(ChatMessage::STATUS_COMPLETED, $assistant->status);
        $this->assertEquals('quota_exceeded', $assistant->result['source']);
    }

    public function test_agent_turn_consumes_summed_tokens(): void
    {
        $fake = Mockery::mock(Entitlements::class);
        $fake->shouldReceive('remaining')->with(Mockery::any(), 'ai_tokens')->andReturn(INF);
        $fake->shouldReceive('consume')->once()->with(Mockery::any(), 'ai_tokens', 15);
        $this->app->instance(Entitlements::class, $fake);

        [$assistant] = $this->makeTurn('log 20 for coffee');

        $router = Mockery::mock(AiRouter::class);
        $router->shouldReceive('classify')->once()->andReturn(AiRouter::ROUTE_AGENT);
        $router->shouldReceive('generateTitle')->andReturn('Log coffee');

        $agent = Mockery::mock(AgentRunner::class);
        $agent->shouldReceive('run')->once()->andReturn([
            'ok' => true,
            'text' => 'Done.',
            'blocks' => [['type' => 'markdown', 'text' => 'Done.']],
            'tool_calls' => [['name' => 'record_transaction', 'arguments' => []]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]);

        (new ProcessChatMessageJob($assistant))->handle(Mockery::mock(AiService::class), $router, $agent);

        $assistant->refresh();
        $this->assertEquals(15, ($assistant->result['usage']['prompt_tokens'] ?? 0)
            + ($assistant->result['usage']['completion_tokens'] ?? 0));
    }

    private function bindLimit(string $key, int $value): void
    {
        $fake = Mockery::mock(Entitlements::class);
        $fake->shouldReceive('limit')->with(Mockery::any(), $key)->andReturn($value);
        $fake->shouldReceive('limit')->andReturn(null);
        $fake->shouldReceive('allows')->andReturn(true);
        $fake->shouldReceive('remaining')->andReturn(INF);
        $fake->shouldReceive('consume');
        $this->app->instance(Entitlements::class, $fake);
    }

    private function makeTurn(string $question): array
    {
        $session = ChatSession::create([
            'owner_type' => $this->user->getMorphClass(),
            'owner_id' => $this->user->getKey(),
        ]);
        $user = $session->messages()->create([
            'user_id' => $this->user->id,
            'role' => ChatMessage::ROLE_USER,
            'content' => $question,
        ]);
        $assistant = $session->messages()->create([
            'role' => ChatMessage::ROLE_ASSISTANT,
            'status' => ChatMessage::STATUS_PENDING,
        ]);

        return [$assistant, $user];
    }
}
