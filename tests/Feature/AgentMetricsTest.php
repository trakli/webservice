<?php

namespace Tests\Feature;

use App\Jobs\ProcessChatMessageJob;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\AgentRunner;
use App\Services\AiRouter;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

class AgentMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_model_call_records_token_usage_for_the_owner(): void
    {
        $user = User::factory()->create();

        app(\App\Ai\Billing\ModelCallGate::class)->record($user, new Usage(24, 1), 'groq', 'test-model');

        $this->assertDatabaseHas('token_usages', [
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->id,
            'operation' => 'model.call',
            'prompt_tokens' => 24,
            'completion_tokens' => 1,
        ]);
        $this->assertSame(25, $user->fresh()->tokensUsed());
    }

    public function test_agent_results_do_not_duplicate_completed_model_usage(): void
    {
        $user = User::factory()->create();
        $session = ChatSession::create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->id,
        ]);
        $session->messages()->create([
            'user_id' => $user->id,
            'role' => ChatMessage::ROLE_USER,
            'content' => 'do a thing',
        ]);
        $assistant = $session->messages()->create([
            'role' => ChatMessage::ROLE_ASSISTANT,
            'status' => ChatMessage::STATUS_PENDING,
        ]);

        $router = Mockery::mock(AiRouter::class);
        $router->shouldReceive('classify')->once()->andReturn(AiRouter::ROUTE_AGENT);
        $router->shouldReceive('generateTitle')->andReturn(null);

        $agent = Mockery::mock(AgentRunner::class);
        $agent->shouldReceive('run')->once()->andReturn([
            'ok' => true,
            'text' => 'done',
            'blocks' => [],
            'tool_calls' => [],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30],
        ]);

        (new ProcessChatMessageJob($assistant))->handle(Mockery::mock(AiService::class), $router, $agent);

        $this->assertDatabaseCount('token_usages', 0);
        $this->assertSame(0, $user->fresh()->tokensUsed());
    }
}
