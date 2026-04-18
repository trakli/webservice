<?php

namespace Tests\Feature;

use App\Jobs\ProcessChatMessageJob;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
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

    public function test_job_writes_successful_response_into_assistant_message(): void
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

        (new ProcessChatMessageJob($assistant))->handle($ai);

        $assistant->refresh();
        $this->assertEquals(ChatMessage::STATUS_COMPLETED, $assistant->status);
        $this->assertEquals('You spent $500.', $assistant->content);
    }

    public function test_job_records_failure(): void
    {
        [$assistant] = $this->makeTurn('noop');

        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('ask')->once()->andReturn([
            'success' => false,
            'error' => 'boom',
        ]);

        (new ProcessChatMessageJob($assistant))->handle($ai);

        $assistant->refresh();
        $this->assertEquals(ChatMessage::STATUS_FAILED, $assistant->status);
        $this->assertEquals('boom', $assistant->error);
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
