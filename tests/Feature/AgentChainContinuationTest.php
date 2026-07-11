<?php

namespace Tests\Feature;

use App\Ai\BlockCollector;
use App\Ai\Tools\Write\CreateWalletTool;
use App\Ai\Tools\Write\RecordTransactionTool;
use App\Jobs\ProcessChatMessageJob;
use App\Models\AgentProposedAction;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;
use Whilesmart\Agents\ValueObjects\ToolContext;

class AgentChainContinuationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ChatSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->session = ChatSession::create([
            'owner_type' => $this->user->getMorphClass(),
            'owner_id' => $this->user->id,
        ]);

        // The original request the assistant should resume after the prerequisite exists.
        $this->session->messages()->create([
            'user_id' => $this->user->id,
            'role' => ChatMessage::ROLE_USER,
            'content' => 'Record a $10 expense in my Chase account',
            'language' => 'en',
        ]);
    }

    private function propose(string $toolClass, array $args): AgentProposedAction
    {
        $this->app->instance(BlockCollector::class, new BlockCollector());
        $context = ToolContext::forUser($this->user, 'en', ['chat_session_id' => $this->session->id]);
        $this->app->make($toolClass)->handle($args, $context);

        return AgentProposedAction::latest('id')->firstOrFail();
    }

    public function test_confirming_a_prerequisite_create_resumes_the_agent_without_a_user_message(): void
    {
        $action = $this->propose(CreateWalletTool::class, [
            'name' => 'Chase',
            'type' => 'bank',
            'currency' => 'USD',
        ]);
        $this->assertSame('wallet.create', $action->action_type);

        $userMessagesBefore = $this->session->messages()->where('role', ChatMessage::ROLE_USER)->count();

        Bus::fake();
        $this->actingAs($this->user)
            ->postJson("/api/v1/ai/chats/{$this->session->id}/actions/{$action->id}/confirm")
            ->assertOk();

        Bus::assertDispatched(ProcessChatMessageJob::class);

        $this->assertSame(
            $userMessagesBefore,
            $this->session->messages()->where('role', ChatMessage::ROLE_USER)->count(),
            'The resume must not fabricate a user message.'
        );

        $this->assertTrue(
            $this->session->messages()
                ->where('role', ChatMessage::ROLE_ASSISTANT)
                ->where('status', ChatMessage::STATUS_PENDING)
                ->exists(),
            'A pending assistant turn should be queued to continue.'
        );
    }

    public function test_confirming_a_transaction_does_not_resume(): void
    {
        Wallet::factory()->create(['user_id' => $this->user->id, 'name' => 'Chase', 'currency' => 'USD']);

        $action = $this->propose(RecordTransactionTool::class, [
            'amount' => 10,
            'type' => 'expense',
            'wallet_name' => 'Chase',
        ]);
        $this->assertSame('transaction.create', $action->action_type);

        Bus::fake();
        $this->actingAs($this->user)
            ->postJson("/api/v1/ai/chats/{$this->session->id}/actions/{$action->id}/confirm")
            ->assertOk();

        Bus::assertNotDispatched(ProcessChatMessageJob::class);
    }
}
