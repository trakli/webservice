<?php

namespace Tests\Feature;

use App\Ai\BlockCollector;
use App\Ai\Tools\Documents\ExtractReceiptTool;
use App\Ai\Tools\Write\AttachToTransactionTool;
use App\Contracts\DocumentProcessor;
use App\Models\AgentProposedAction;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\DocumentProcessorManager;
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

    public function test_extract_receipt_proposes_a_transaction_from_the_first_suggestion(): void
    {
        $this->attachFileToChat();

        $processor = \Mockery::mock(DocumentProcessor::class);
        $processor->shouldReceive('process')->andReturn([
            ['amount' => 12.5, 'type' => 'expense', 'description' => 'Coffee shop', 'date' => '2026-06-01'],
        ]);
        $manager = \Mockery::mock(DocumentProcessorManager::class);
        $manager->shouldReceive('canHandle')->andReturn(true);
        $manager->shouldReceive('getProcessor')->andReturn($processor);
        $this->app->instance(DocumentProcessorManager::class, $manager);
        $this->app->instance(BlockCollector::class, new BlockCollector());

        $this->app->make(ExtractReceiptTool::class)->handle([], $this->context());

        $action = AgentProposedAction::latest('id')->firstOrFail();
        $this->assertSame('transaction.create', $action->action_type);
        $this->assertEquals(12.5, $action->payload['amount']);
        $this->assertEquals('Coffee shop', $action->payload['description']);
        $this->assertEquals($this->wallet->id, $action->payload['wallet_id']);
    }
}
