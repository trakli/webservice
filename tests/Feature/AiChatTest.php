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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use Whilesmart\Agents\Facades\Agents;
use Whilesmart\Agents\ValueObjects\AgentResult;
use Whilesmart\Roles\Models\Role;

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

    public function test_attachment_turn_defers_the_job_until_files_are_uploaded(): void
    {
        Bus::fake();
        Storage::fake();

        $created = $this->actingAs($this->user)->postJson('/api/v1/ai/chats', [
            'message' => 'Attached statement.csv',
            'defer_processing' => true,
        ]);
        $created->assertStatus(202);

        // Held back: dispatching now would race the not-yet-uploaded file.
        Bus::assertNotDispatched(ProcessChatMessageJob::class);

        $sessionId = $created->json('data.id');
        $userMessageId = collect($created->json('data.messages'))->firstWhere('role', 'user')['id'];

        $this->actingAs($this->user)
            ->postJson("/api/v1/ai/chats/{$sessionId}/messages/{$userMessageId}/files", [
                'files' => [UploadedFile::fake()->create('statement.csv', 12, 'text/csv')],
            ])
            ->assertStatus(200);

        // Released once the file landed.
        Bus::assertDispatched(ProcessChatMessageJob::class, 1);
    }

    public function test_attachment_forces_the_agent_route(): void
    {
        Storage::fake();
        [$assistant, $user] = $this->makeTurn('Attached statement.csv');
        $user->files()->create(['path' => 'chat_attachments/statement.csv', 'type' => 'document']);

        // A caption like "Attached ..." would otherwise classify as general chat;
        // the presence of a file must route straight to the agent.
        $router = Mockery::mock(AiRouter::class);
        $router->shouldNotReceive('classify');
        $router->shouldReceive('generateTitle')->andReturn('Statement');

        $agent = Mockery::mock(AgentRunner::class);
        $agent->shouldReceive('run')->once()->andReturn([
            'ok' => true,
            'text' => 'Here is your import review.',
            'blocks' => [],
            'tool_calls' => [],
            'usage' => [],
        ]);

        (new ProcessChatMessageJob($assistant))->handle(Mockery::mock(AiService::class), $router, $agent);

        $assistant->refresh();
        $this->assertEquals('agent', $assistant->result['source']);
    }

    public function test_agent_input_includes_prior_turns_and_attachment_notice(): void
    {
        Storage::fake();
        $session = $this->makeSession();
        $session->messages()->create([
            'user_id' => $this->user->id, 'role' => ChatMessage::ROLE_USER, 'content' => 'Import my bank statement',
        ]);
        $session->messages()->create([
            'role' => ChatMessage::ROLE_ASSISTANT, 'status' => ChatMessage::STATUS_COMPLETED, 'content' => 'Please upload your statement.',
        ]);
        $userMsg = $session->messages()->create([
            'user_id' => $this->user->id, 'role' => ChatMessage::ROLE_USER, 'content' => 'here it is',
        ]);
        $userMsg->files()->create([
            'path' => 'chat_attachments/statement.csv', 'type' => 'document', 'metadata' => ['document_type' => 'bank_statement'],
        ]);

        $captured = null;
        Agents::shouldReceive('run')->once()->andReturnUsing(function ($harness, $input) use (&$captured) {
            $captured = $input;

            return AgentResult::success('ok');
        });

        app(AgentRunner::class)->run($userMsg, null);

        $this->assertStringContainsString('Import my bank statement', $captured);
        $this->assertStringContainsString('Please upload your statement.', $captured);
        $this->assertStringContainsString('here it is', $captured);
        $this->assertStringContainsString('statement.csv', $captured);
        $this->assertStringContainsString('bank_statement', $captured);
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

        (new ProcessChatMessageJob($assistant))->handle($ai, $router, app(AgentRunner::class));

        $assistant->refresh();
        $this->assertEquals(ChatMessage::STATUS_COMPLETED, $assistant->status);
        $this->assertEquals('You spent $500.', $assistant->content);
        $this->assertEquals('smartql', $assistant->result['source']);
    }

    public function test_job_routes_action_question_to_agent_and_stores_blocks(): void
    {
        [$assistant] = $this->makeTurn('log 20 for coffee');

        $ai = Mockery::mock(AiService::class);
        $ai->shouldNotReceive('ask');

        $router = Mockery::mock(AiRouter::class);
        $router->shouldReceive('classify')->once()->andReturn(AiRouter::ROUTE_AGENT);
        $router->shouldReceive('generateTitle')->andReturn('Log coffee');

        $agent = Mockery::mock(AgentRunner::class);
        $agent->shouldReceive('run')->once()->andReturn([
            'ok' => true,
            'text' => 'Done. Logged a **20** expense for coffee.',
            'blocks' => [['type' => 'markdown', 'text' => 'Done. Logged a **20** expense for coffee.']],
            'tool_calls' => [['name' => 'record_transaction', 'arguments' => []]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]);

        (new ProcessChatMessageJob($assistant))->handle($ai, $router, $agent);

        $assistant->refresh();
        $this->assertEquals(ChatMessage::STATUS_COMPLETED, $assistant->status);
        $this->assertEquals('agent', $assistant->result['source']);
        $this->assertEquals('markdown', $assistant->result['blocks'][0]['type']);
        $this->assertNotEmpty($assistant->result['tool_calls']);
    }

    public function test_job_falls_back_to_prism_when_agent_fails(): void
    {
        [$assistant] = $this->makeTurn('do something the agent cannot');

        $ai = Mockery::mock(AiService::class);

        $router = Mockery::mock(AiRouter::class);
        $router->shouldReceive('classify')->once()->andReturn(AiRouter::ROUTE_AGENT);
        $router->shouldReceive('answerGeneral')->once()
            ->andReturn(['success' => true, 'text' => 'Sorry, I could not do that.']);
        $router->shouldReceive('generateTitle')->andReturn(null);

        $agent = Mockery::mock(AgentRunner::class);
        $agent->shouldReceive('run')->once()->andReturn(['ok' => false, 'error' => 'boom']);

        (new ProcessChatMessageJob($assistant))->handle($ai, $router, $agent);

        $assistant->refresh();
        $this->assertEquals(ChatMessage::STATUS_COMPLETED, $assistant->status);
        $this->assertEquals('prism_fallback', $assistant->result['source']);
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

        (new ProcessChatMessageJob($assistant))->handle($ai, $router, app(AgentRunner::class));

        $assistant->refresh();
        $this->assertEquals(ChatMessage::STATUS_COMPLETED, $assistant->status);
        $this->assertEquals('An expense is money you spend.', $assistant->content);
        $this->assertEquals('prism', $assistant->result['source']);
    }

    public function test_job_reports_unavailable_when_smartql_infra_fails(): void
    {
        // A SmartQL hard failure (down, expired key, timeout) is not the user's
        // fault, show "unavailable", not a "rephrase" suggestion.
        [$assistant] = $this->makeTurn('how much did I spend last month?');

        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('ask')->once()->andReturn([
            'success' => false,
            'error' => 'SQL generation failed: API key expired',
        ]);

        $router = Mockery::mock(AiRouter::class);
        $router->shouldReceive('classify')->once()->andReturn(AiRouter::ROUTE_DATA);
        $router->shouldNotReceive('answerGeneral');
        $router->shouldReceive('generateTitle')->andReturn(null);

        (new ProcessChatMessageJob($assistant))->handle($ai, $router, app(AgentRunner::class));

        $assistant->refresh();
        $this->assertEquals(ChatMessage::STATUS_COMPLETED, $assistant->status);
        $this->assertStringContainsString('unavailable', $assistant->content);
        $this->assertEquals('unavailable', $assistant->result['source']);
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

        (new ProcessChatMessageJob($assistant))->handle($ai, $router, app(AgentRunner::class));

        $assistant->refresh();
        $this->assertEquals('prism_fallback', $assistant->result['source']);
    }

    public function test_smartql_context_is_scoped_to_the_authenticated_user(): void
    {
        Http::fake([
            '*/ask' => Http::response([
                'human_response' => 'ok',
                'format_type' => 'scalar',
                'rows' => [['total' => 1]],
            ]),
        ]);

        [$assistant] = $this->makeTurn('show all transactions');

        $router = Mockery::mock(AiRouter::class);
        $router->shouldReceive('classify')->once()->andReturn(AiRouter::ROUTE_DATA);
        $router->shouldReceive('generateTitle')->andReturn('All transactions');

        (new ProcessChatMessageJob($assistant))->handle(app(AiService::class), $router, app(AgentRunner::class));

        Http::assertSent(function ($request) {
            return $request['context']['user_id'] === $this->user->id
                && ! array_key_exists('role', $request['context']);
        });
    }

    public function test_admin_users_send_their_role_in_smartql_context(): void
    {
        Role::firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Admin', 'description' => 'System administrator']
        );
        $this->user->assignRole('admin');

        Http::fake([
            '*/ask' => Http::response([
                'human_response' => 'ok',
                'format_type' => 'scalar',
                'rows' => [['total' => 1]],
            ]),
        ]);

        [$assistant] = $this->makeTurn('show all transactions');

        $router = Mockery::mock(AiRouter::class);
        $router->shouldReceive('classify')->once()->andReturn(AiRouter::ROUTE_DATA);
        $router->shouldReceive('generateTitle')->andReturn('All transactions');

        (new ProcessChatMessageJob($assistant))->handle(app(AiService::class), $router, app(AgentRunner::class));

        Http::assertSent(function ($request) {
            return $request['context']['user_id'] === $this->user->id
                && ($request['context']['role'] ?? null) === 'admin';
        });
    }

    public function test_smartql_query_tool_scopes_to_user_and_returns_rows(): void
    {
        Http::fake([
            '*/ask' => Http::response([
                'rows' => [['total' => 500]],
                'explanation' => 'You spent 500.',
                'format_type' => 'scalar',
            ]),
        ]);

        $tool = app(\App\Ai\Tools\Read\SmartqlQueryTool::class);
        $context = \Whilesmart\Agents\ValueObjects\ToolContext::forUser($this->user);

        $out = $tool->handle(['question' => 'total spent last month'], $context);

        $this->assertEquals([['total' => 500]], $out['rows']);
        $this->assertEquals('scalar', $out['format_type']);

        Http::assertSent(function ($request) {
            return $request['context']['user_id'] === $this->user->id
                && $request['generate_response'] === false;
        });
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
