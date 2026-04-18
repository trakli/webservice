<?php

namespace Tests\Feature;

use App\Jobs\ProcessChatMessageJob;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\AiRouter;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class AiChatTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_starting_a_chat_dispatches_a_job_and_creates_paired_messages(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)->postJson('/api/v1/ai/chats', [
            'message' => 'How much did I spend last month?',
        ]);

        $response->assertStatus(202);
        $data = $response->json('data');

        $this->assertCount(2, $data['messages']);
        $this->assertEquals('user', $data['messages'][0]['role']);
        $this->assertEquals($this->user->id, $data['messages'][0]['user_id']);
        $this->assertEquals('assistant', $data['messages'][1]['role']);
        $this->assertNull($data['messages'][1]['user_id']);
        $this->assertEquals('pending', $data['messages'][1]['status']);

        Bus::assertDispatched(ProcessChatMessageJob::class, 1);
    }

    public function test_follow_up_dispatches_another_job(): void
    {
        Bus::fake();

        $session = $this->makeSession();

        $this->actingAs($this->user)
            ->postJson("/api/v1/ai/chats/{$session->id}/messages", ['message' => 'and before?'])
            ->assertStatus(202);

        Bus::assertDispatched(ProcessChatMessageJob::class, 1);
        $this->assertEquals(2, $session->messages()->count());
    }

    public function test_polling_returns_session_with_messages(): void
    {
        $session = $this->makeSession();
        $session->messages()->create([
            'user_id' => $this->user->id,
            'role' => ChatMessage::ROLE_USER,
            'content' => 'hi',
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/ai/chats/{$session->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.messages.0.content', 'hi');
    }

    public function test_cannot_access_another_users_session(): void
    {
        $other = User::factory()->create();
        $session = ChatSession::create([
            'owner_type' => $other->getMorphClass(),
            'owner_id' => $other->getKey(),
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/ai/chats/{$session->id}")
            ->assertStatus(404);
    }

    public function test_listing_scopes_to_owner(): void
    {
        ChatSession::create(['owner_type' => $this->user->getMorphClass(), 'owner_id' => $this->user->getKey(), 'title' => 'mine']);
        $other = User::factory()->create();
        ChatSession::create(['owner_type' => $other->getMorphClass(), 'owner_id' => $other->getKey(), 'title' => 'theirs']);

        $titles = array_column(
            $this->actingAs($this->user)->getJson('/api/v1/ai/chats')->json('data.data'),
            'title'
        );
        $this->assertContains('mine', $titles);
        $this->assertNotContains('theirs', $titles);
    }

    public function test_delete_cascades(): void
    {
        $session = $this->makeSession();
        $session->messages()->create(['user_id' => $this->user->id, 'role' => ChatMessage::ROLE_USER, 'content' => 'x']);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/ai/chats/{$session->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('chat_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('chat_messages', ['chat_session_id' => $session->id]);
    }

    public function test_job_routes_data_question_to_smartql(): void
    {
        [$assistant] = $this->makeTurn('spending last month?');

        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('ask')->once()->andReturn([
            'success' => true,
            'data' => [
                'human_response' => 'You spent $500.',
                'format_type' => 'scalar',
                'rows' => [['total' => 500]],
            ],
        ]);

        $router = Mockery::mock(AiRouter::class);
        $router->shouldReceive('classify')->once()->andReturn(AiRouter::ROUTE_DATA);
        $router->shouldReceive('generateTitle')->andReturn('Spending summary');

        (new ProcessChatMessageJob($assistant))->handle($ai, $router);

        $assistant->refresh();
        $this->assertEquals(ChatMessage::STATUS_COMPLETED, $assistant->status);
        $this->assertEquals('You spent $500.', $assistant->content);
        $this->assertEquals('smartql', $assistant->result['source']);
    }

    public function test_job_routes_general_question_to_prism(): void
    {
        [$assistant] = $this->makeTurn('What does expense mean?');

        $ai = Mockery::mock(AiService::class);
        $ai->shouldNotReceive('ask');

        $router = Mockery::mock(AiRouter::class);
        $router->shouldReceive('classify')->once()->andReturn(AiRouter::ROUTE_GENERAL);
        $router->shouldReceive('answerGeneral')->once()->with('What does expense mean?', null)
            ->andReturn(['success' => true, 'text' => 'An expense is money you spend.']);
        $router->shouldReceive('generateTitle')->andReturn('Definition of expense');

        (new ProcessChatMessageJob($assistant))->handle($ai, $router);

        $assistant->refresh();
        $this->assertEquals(ChatMessage::STATUS_COMPLETED, $assistant->status);
        $this->assertEquals('An expense is money you spend.', $assistant->content);
        $this->assertEquals('prism', $assistant->result['source']);
    }

    public function test_job_falls_back_to_prism_when_smartql_fails(): void
    {
        [$assistant] = $this->makeTurn('something SmartQL cannot handle');

        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('ask')->once()->andReturn([
            'success' => false,
            'error' => 'SQL generation failed',
        ]);

        $router = Mockery::mock(AiRouter::class);
        $router->shouldReceive('classify')->once()->andReturn(AiRouter::ROUTE_DATA);
        $router->shouldReceive('answerGeneral')->once()
            ->with('something SmartQL cannot handle', 'SQL generation failed')
            ->andReturn(['success' => true, 'text' => "I couldn't query your data for that."]);
        $router->shouldReceive('generateTitle')->andReturn(null);

        (new ProcessChatMessageJob($assistant))->handle($ai, $router);

        $assistant->refresh();
        $this->assertEquals(ChatMessage::STATUS_COMPLETED, $assistant->status);
        $this->assertEquals("I couldn't query your data for that.", $assistant->content);
        $this->assertEquals('prism_fallback', $assistant->result['source']);
    }

    public function test_job_falls_back_when_smartql_returns_empty_rows(): void
    {
        [$assistant] = $this->makeTurn('weird question');

        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('ask')->once()->andReturn([
            'success' => true,
            'data' => ['rows' => [], 'format_type' => 'table'],
        ]);

        $router = Mockery::mock(AiRouter::class);
        $router->shouldReceive('classify')->once()->andReturn(AiRouter::ROUTE_DATA);
        $router->shouldReceive('answerGeneral')->once()
            ->andReturn(['success' => true, 'text' => 'No data matched.']);
        $router->shouldReceive('generateTitle')->andReturn(null);

        (new ProcessChatMessageJob($assistant))->handle($ai, $router);

        $assistant->refresh();
        $this->assertEquals('prism_fallback', $assistant->result['source']);
    }

    private function makeSession(): ChatSession
    {
        return ChatSession::create([
            'owner_type' => $this->user->getMorphClass(),
            'owner_id' => $this->user->getKey(),
        ]);
    }

    /**
     * @return array{0: ChatMessage, 1: ChatMessage}
     */
    private function makeTurn(string $question): array
    {
        $session = $this->makeSession();
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
