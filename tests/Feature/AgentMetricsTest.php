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
use Tests\TestCase;

class AgentMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_agent_turn_records_token_usage_for_the_owner(): void
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

        $this->assertDatabaseHas('token_usages', [
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->id,
            'operation' => 'chat.agent',
            'prompt_tokens' => 120,
            'completion_tokens' => 30,
        ]);

        $this->assertSame(150, $user->fresh()->tokensUsed());
    }
}
