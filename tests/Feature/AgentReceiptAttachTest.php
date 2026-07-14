<?php

namespace Tests\Feature;

use App\Ai\BlockCollector;
use App\Ai\Tools\Write\AttachToTransactionTool;
use App\Models\AgentProposedAction;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Whilesmart\Agents\ValueObjects\ToolContext;

class AgentReceiptAttachTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Wallet $wallet;

    protected ChatSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->wallet = Wallet::factory()->create(['user_id' => $this->user->id, 'name' => 'Cash', 'currency' => 'USD']);
        $this->session = ChatSession::create(['owner_type' => $this->user->getMorphClass(), 'owner_id' => $this->user->id]);
    }

    private function attachFileToChat(): void
    {
        Storage::fake();
        Storage::put('chat_attachments/receipt.png', 'x');
        $msg = $this->session->messages()->create(['user_id' => $this->user->id, 'role' => ChatMessage::ROLE_USER, 'content' => 'here']);
        $msg->files()->create(['path' => 'chat_attachments/receipt.png', 'type' => 'image', 'metadata' => ['document_type' => 'receipt']]);
    }

    private function context(): ToolContext
    {
        return ToolContext::forUser($this->user, 'en', ['chat_session_id' => $this->session->id]);
    }

    public function test_attach_to_transaction_proposes_and_confirms(): void
    {
        $this->attachFileToChat();
        $transaction = Transaction::factory()->create(['user_id' => $this->user->id, 'wallet_id' => $this->wallet->id]);

        $this->app->instance(BlockCollector::class, new BlockCollector());
        $this->app->make(AttachToTransactionTool::class)->handle(['transaction_id' => $transaction->id], $this->context());

        $action = AgentProposedAction::latest('id')->firstOrFail();
        $this->assertSame('transaction.attach_file', $action->action_type);

        $this->actingAs($this->user)
            ->postJson("/api/v1/ai/chats/{$this->session->id}/actions/{$action->id}/confirm")
            ->assertStatus(200);

        $this->assertSame(1, $transaction->fresh()->files()->count());
    }
}
