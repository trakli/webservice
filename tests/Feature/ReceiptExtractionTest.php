<?php

namespace Tests\Feature;

use App\Ai\BlockCollector;
use App\Ai\Tools\Documents\ExtractReceiptTool;
use App\Models\AgentProposedAction;
use App\Models\Category;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Party;
use App\Models\User;
use App\Models\Wallet;
use App\Services\DocumentProcessorManager;
use App\Services\DocumentProcessors\RemoteDocumentProcessor;
use App\Types\TransactionSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use Whilesmart\Agents\ValueObjects\ToolContext;

class ReceiptExtractionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ChatSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Wallet::factory()->create(['user_id' => $this->user->id]);
        $this->session = ChatSession::create([
            'owner_type' => $this->user->getMorphClass(),
            'owner_id' => $this->user->id,
        ]);
    }

    private function proposeReceipt(TransactionSuggestion $suggestion): AgentProposedAction
    {
        Storage::fake('local');
        Storage::put('chat_attachments/receipt.jpg', 'img');
        $message = $this->session->messages()->create([
            'user_id' => $this->user->id,
            'role' => ChatMessage::ROLE_USER,
            'content' => 'Attached receipt.jpg',
        ]);
        $message->files()->create(['path' => 'chat_attachments/receipt.jpg', 'type' => 'image']);

        $processor = Mockery::mock(RemoteDocumentProcessor::class);
        $processor->shouldReceive('extractReceipt')->once()->andReturn($suggestion);

        $manager = Mockery::mock(DocumentProcessorManager::class);
        $manager->shouldReceive('canHandle')->andReturn(true);
        $manager->shouldReceive('getProcessor')->andReturn($processor);

        $this->app->instance(DocumentProcessorManager::class, $manager);
        $this->app->instance(BlockCollector::class, new BlockCollector());

        $this->app->make(ExtractReceiptTool::class)->handle(
            [],
            ToolContext::forUser($this->user, 'en', ['chat_session_id' => $this->session->id]),
        );

        return AgentProposedAction::latest('id')->firstOrFail();
    }

    public function test_a_receipt_proposes_one_expense_with_the_printed_total(): void
    {
        $action = $this->proposeReceipt(new TransactionSuggestion(
            amount: 42.50,
            type: 'expense',
            party: 'Corner Store',
            category: null,
            description: 'Groceries',
            date: '2026-07-01',
        ));

        $this->assertSame('transaction.create', $action->action_type);
        $this->assertEquals(42.50, (float) $action->payload['amount']);
        $this->assertSame('expense', $action->payload['type']);
        // An unknown merchant is kept in the description, not dropped.
        $this->assertStringContainsString('Corner Store', $action->payload['description']);
        $this->assertArrayNotHasKey('party_id', $action->payload);
    }

    public function test_a_known_merchant_and_category_are_attached(): void
    {
        $party = Party::factory()->create(['user_id' => $this->user->id, 'name' => 'Walmart']);
        $category = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries']);

        $action = $this->proposeReceipt(new TransactionSuggestion(
            amount: 88.00,
            type: 'expense',
            party: 'Walmart',
            category: 'Groceries',
            description: 'weekly shop',
            date: '2026-07-02',
        ));

        $this->assertEquals($party->id, $action->payload['party_id']);
        $this->assertContains($category->id, $action->payload['categories']);
    }
}
