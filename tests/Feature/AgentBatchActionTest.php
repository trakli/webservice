<?php

namespace Tests\Feature;

use App\Ai\BlockCollector;
use App\Ai\Tools\Write\AssignTransactionCategoriesTool;
use App\Ai\Tools\Write\CategorizeTransactionsTool;
use App\Models\AgentProposedAction;
use App\Models\Category;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Whilesmart\AgentActions\Enums\ActionStatus;
use Whilesmart\Agents\ValueObjects\ToolContext;

class AgentBatchActionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Wallet $wallet;

    private ChatSession $session;

    private ChatMessage $message;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->wallet = Wallet::factory()->create(['user_id' => $this->user->id, 'currency' => 'XAF']);

        $this->session = new ChatSession(['title' => 'Batch']);
        $this->session->owner()->associate($this->user);
        $this->session->save();

        $this->message = ChatMessage::create([
            'chat_session_id' => $this->session->id,
            'user_id' => $this->user->id,
            'role' => 'assistant',
            'content' => 'ok',
        ]);

        app()->instance(BlockCollector::class, new BlockCollector());
    }

    private function context(): ToolContext
    {
        return ToolContext::forUser($this->user, 'en', [
            'chat_session_id' => $this->session->id,
            'chat_message_id' => $this->message->id,
        ]);
    }

    private function transaction(string $description): Transaction
    {
        return Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'description' => $description,
        ]);
    }

    private function category(string $name): Category
    {
        return Category::factory()->create([
            'user_id' => $this->user->id,
            'name' => $name,
            'type' => 'expense',
        ]);
    }

    private function block(): array
    {
        return app(BlockCollector::class)->all()[0];
    }

    public function test_categorizing_many_transactions_proposes_one_batch(): void
    {
        $a = $this->transaction('Coffee');
        $b = $this->transaction('Lunch');
        $groceries = $this->category('Groceries');

        app(CategorizeTransactionsTool::class)->handle([
            'transaction_ids' => [$a->id, $b->id],
            'category_name' => 'Groceries',
        ], $this->context());

        $block = $this->block();

        $this->assertSame('proposed_action_batch', $block['type']);
        $this->assertCount(2, $block['actions']);
        $this->assertSame('Categorize 2 transactions as Groceries', $block['summary']);

        // One ledger row per transaction, all sharing one batch id.
        $rows = AgentProposedAction::query()->forBatch($block['batch'])->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $rows->pluck('payload.transaction_id')->all()
        );
        $this->assertSame([$groceries->id], $rows->first()->payload['categories']);
    }

    public function test_a_single_transaction_stays_a_plain_proposal(): void
    {
        $a = $this->transaction('Coffee');
        $this->category('Groceries');

        app(CategorizeTransactionsTool::class)->handle([
            'transaction_ids' => [$a->id],
            'category_name' => 'Groceries',
        ], $this->context());

        $this->assertSame('proposed_action', $this->block()['type']);
    }

    public function test_cards_name_the_transaction_and_category_rather_than_ids(): void
    {
        $a = $this->transaction('Flat white');
        $this->category('Coffee');

        app(CategorizeTransactionsTool::class)->handle([
            'transaction_ids' => [$a->id],
            'category_name' => 'Coffee',
        ], $this->context());

        $block = $this->block();

        $this->assertStringContainsString('Flat white', $block['summary']);
        $this->assertStringNotContainsString('#' . $a->id, $block['summary']);

        $fields = collect($block['fields'])->keyBy('key');
        $this->assertStringContainsString('Flat white', $fields['transaction_id']['display']);
        $this->assertStringContainsString('XAF', $fields['transaction_id']['display']);
        $this->assertSame('Coffee', $fields['categories']['display']);
    }

    public function test_assigning_a_different_category_to_each_transaction(): void
    {
        $a = $this->transaction('Coffee');
        $b = $this->transaction('Bus fare');
        $coffee = $this->category('Coffee');
        $transport = $this->category('Transport');

        app(AssignTransactionCategoriesTool::class)->handle([
            'assignments' => [
                ['transaction_id' => $a->id, 'category_name' => 'Coffee'],
                ['transaction_id' => $b->id, 'category_name' => 'Transport'],
            ],
        ], $this->context());

        $block = $this->block();
        $rows = AgentProposedAction::query()->forBatch($block['batch'])->get()->keyBy('payload.transaction_id');

        $this->assertSame([$coffee->id], $rows[$a->id]->payload['categories']);
        $this->assertSame([$transport->id], $rows[$b->id]->payload['categories']);
        $this->assertSame('Categorize 2 transactions across 2 categories', $block['summary']);
    }

    public function test_assignment_rejects_unknown_categories_naming_all_of_them(): void
    {
        $a = $this->transaction('Coffee');

        $result = app(AssignTransactionCategoriesTool::class)->handle([
            'assignments' => [
                ['transaction_id' => $a->id, 'category_name' => 'Nope'],
                ['transaction_id' => $a->id, 'category_name' => 'Also nope'],
            ],
        ], $this->context());

        $this->assertStringContainsString('do not exist yet', $result['error']);
    }

    public function test_a_transaction_from_another_user_is_refused(): void
    {
        $mine = $this->transaction('Coffee');
        $other = User::factory()->create();
        $theirs = Transaction::factory()->create([
            'user_id' => $other->id,
            'wallet_id' => Wallet::factory()->create(['user_id' => $other->id])->id,
        ]);
        $this->category('Groceries');

        $result = app(CategorizeTransactionsTool::class)->handle([
            'transaction_ids' => [$mine->id, $theirs->id],
            'category_name' => 'Groceries',
        ], $this->context());

        $this->assertStringContainsString('not found among your records', $result['error']);
        $this->assertSame(0, AgentProposedAction::query()->count());
    }

    public function test_confirming_a_batch_categorizes_every_member(): void
    {
        $a = $this->transaction('Coffee');
        $b = $this->transaction('Lunch');
        $groceries = $this->category('Groceries');

        app(CategorizeTransactionsTool::class)->handle([
            'transaction_ids' => [$a->id, $b->id],
            'category_name' => 'Groceries',
        ], $this->context());

        $batch = $this->block()['batch'];

        $this->actingAs($this->user)
            ->postJson("/api/v1/ai/chats/{$this->session->id}/actions/batches/{$batch}/confirm")
            ->assertOk()
            ->assertJsonPath('data.executed', 2);

        $this->assertSame([$groceries->id], $a->fresh()->categories->pluck('id')->all());
        $this->assertSame([$groceries->id], $b->fresh()->categories->pluck('id')->all());
        $this->assertSame(
            [ActionStatus::Executed->value, ActionStatus::Executed->value],
            AgentProposedAction::query()->forBatch($batch)->pluck('status')->map->value->all()
        );
    }

    public function test_confirming_a_batch_twice_does_not_duplicate_work(): void
    {
        $a = $this->transaction('Coffee');
        $this->transaction('Lunch');
        $this->category('Groceries');

        app(CategorizeTransactionsTool::class)->handle([
            'transaction_ids' => [$a->id, Transaction::query()->latest('id')->first()->id],
            'category_name' => 'Groceries',
        ], $this->context());

        $batch = $this->block()['batch'];
        $url = "/api/v1/ai/chats/{$this->session->id}/actions/batches/{$batch}/confirm";

        $this->actingAs($this->user)->postJson($url)->assertOk();
        $this->actingAs($this->user)->postJson($url)->assertOk()->assertJsonPath('data.executed', 2);

        $this->assertCount(1, $a->fresh()->categories);
    }

    public function test_dismissing_a_batch_changes_nothing(): void
    {
        $a = $this->transaction('Coffee');
        $b = $this->transaction('Lunch');
        $this->category('Groceries');

        app(CategorizeTransactionsTool::class)->handle([
            'transaction_ids' => [$a->id, $b->id],
            'category_name' => 'Groceries',
        ], $this->context());

        $batch = $this->block()['batch'];

        $this->actingAs($this->user)
            ->postJson("/api/v1/ai/chats/{$this->session->id}/actions/batches/{$batch}/reject")
            ->assertOk();

        $this->assertCount(0, $a->fresh()->categories);
        $this->assertCount(0, $b->fresh()->categories);
    }

    public function test_another_user_cannot_confirm_a_batch(): void
    {
        $a = $this->transaction('Coffee');
        $b = $this->transaction('Lunch');
        $this->category('Groceries');

        app(CategorizeTransactionsTool::class)->handle([
            'transaction_ids' => [$a->id, $b->id],
            'category_name' => 'Groceries',
        ], $this->context());

        $batch = $this->block()['batch'];

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/ai/chats/{$this->session->id}/actions/batches/{$batch}/confirm")
            ->assertNotFound();

        $this->assertCount(0, $a->fresh()->categories);
    }

    public function test_confirming_a_batch_marks_the_stored_card_done(): void
    {
        $a = $this->transaction('Coffee');
        $b = $this->transaction('Lunch');
        $this->category('Groceries');

        app(CategorizeTransactionsTool::class)->handle([
            'transaction_ids' => [$a->id, $b->id],
            'category_name' => 'Groceries',
        ], $this->context());

        $block = $this->block();
        $this->message->update(['result' => ['blocks' => [$block]]]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/ai/chats/{$this->session->id}/actions/batches/{$block['batch']}/confirm")
            ->assertOk();

        $stored = $this->message->fresh()->result['blocks'][0];

        $this->assertSame(ActionStatus::Executed->value, $stored['status']);
        $this->assertSame(
            [ActionStatus::Executed->value, ActionStatus::Executed->value],
            array_column($stored['actions'], 'status')
        );
    }

    public function test_a_batch_stays_pending_until_every_member_is_settled(): void
    {
        $a = $this->transaction('Coffee');
        $b = $this->transaction('Lunch');
        $this->category('Groceries');

        app(CategorizeTransactionsTool::class)->handle([
            'transaction_ids' => [$a->id, $b->id],
            'category_name' => 'Groceries',
        ], $this->context());

        $block = $this->block();
        $this->message->update(['result' => ['blocks' => [$block]]]);
        $first = $block['actions'][0]['id'];

        // Confirming one member on its own must not settle the whole card.
        $this->actingAs($this->user)
            ->postJson("/api/v1/ai/chats/{$this->session->id}/actions/{$first}/confirm")
            ->assertOk();

        $stored = $this->message->fresh()->result['blocks'][0];

        $this->assertSame(ActionStatus::Proposed->value, $stored['status']);
        $this->assertSame(ActionStatus::Executed->value, $stored['actions'][0]['status']);
        $this->assertSame(ActionStatus::Proposed->value, $stored['actions'][1]['status']);
    }
}
