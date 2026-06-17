<?php

namespace Tests\Feature;

use App\Ai\BlockCollector;
use App\Ai\Tools\Write\RecordTransactionTool;
use App\Models\AgentProposedAction;
use App\Models\Category;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Whilesmart\Agents\ValueObjects\ToolContext;

class AgentActionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Wallet $wallet;

    protected ChatSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->wallet = Wallet::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Cash',
            'currency' => 'USD',
        ]);
        $this->session = ChatSession::create([
            'owner_type' => $this->user->getMorphClass(),
            'owner_id' => $this->user->id,
        ]);
    }

    private function propose(array $args): AgentProposedAction
    {
        $this->app->instance(BlockCollector::class, new BlockCollector());
        $tool = $this->app->make(RecordTransactionTool::class);
        $context = ToolContext::forUser($this->user, 'en', ['chat_session_id' => $this->session->id]);
        $tool->handle($args, $context);

        return AgentProposedAction::latest("id")->firstOrFail();
    }

    public function test_write_tool_proposes_without_mutating(): void
    {
        $collector = new BlockCollector();
        $this->app->instance(BlockCollector::class, $collector);
        $tool = $this->app->make(RecordTransactionTool::class);
        $context = ToolContext::forUser($this->user, 'en', ['chat_session_id' => $this->session->id]);

        $before = Transaction::count();

        $message = $tool->handle([
            'amount' => 20,
            'type' => 'expense',
            'wallet_name' => 'Cash',
            'description' => 'coffee',
        ], $context);

        $this->assertStringContainsString('awaiting', $message);
        $this->assertSame($before, Transaction::count(), 'Proposing must not create a transaction.');

        $action = AgentProposedAction::latest("id")->firstOrFail();
        $this->assertEquals(AgentProposedAction::STATUS_PROPOSED, $action->status);
        $this->assertEquals($this->wallet->id, $action->payload['wallet_id']);
        $this->assertEquals('proposed_action', $collector->all()[0]['type']);
    }

    public function test_confirm_executes_through_the_user_boundary_and_audits(): void
    {
        $action = $this->propose(['amount' => 20, 'type' => 'expense', 'wallet_name' => 'Cash']);
        $before = Transaction::count();

        $this->actingAs($this->user)
            ->postJson("/api/v1/ai/chats/{$this->session->id}/actions/{$action->id}/confirm")
            ->assertStatus(200);

        $this->assertSame($before + 1, Transaction::count());
        $this->assertEquals(AgentProposedAction::STATUS_EXECUTED, $action->fresh()->status);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'amount' => 20,
            'type' => 'expense',
        ]);
        $this->assertDatabaseHas('activities', [
            'action' => 'transaction.create',
            'source' => 'agent',
            'actor_id' => $this->user->id,
        ]);
    }

    public function test_confirm_is_idempotent(): void
    {
        $action = $this->propose(['amount' => 20, 'type' => 'expense', 'wallet_name' => 'Cash']);
        $before = Transaction::count();

        $url = "/api/v1/ai/chats/{$this->session->id}/actions/{$action->id}/confirm";
        $this->actingAs($this->user)->postJson($url)->assertStatus(200);
        $this->actingAs($this->user)->postJson($url)->assertStatus(200);

        $this->assertSame($before + 1, Transaction::count(), 'A retried confirm must not double-record.');
    }

    public function test_reject_does_not_execute(): void
    {
        $action = $this->propose(['amount' => 20, 'type' => 'expense', 'wallet_name' => 'Cash']);
        $before = Transaction::count();

        $this->actingAs($this->user)
            ->postJson("/api/v1/ai/chats/{$this->session->id}/actions/{$action->id}/reject")
            ->assertStatus(200);

        $this->assertEquals(AgentProposedAction::STATUS_REJECTED, $action->fresh()->status);
        $this->assertSame($before, Transaction::count());
    }

    public function test_another_user_cannot_confirm_an_action(): void
    {
        $action = $this->propose(['amount' => 20, 'type' => 'expense', 'wallet_name' => 'Cash']);
        $intruder = User::factory()->create();
        $before = Transaction::count();

        $this->actingAs($intruder)
            ->postJson("/api/v1/ai/chats/{$this->session->id}/actions/{$action->id}/confirm")
            ->assertStatus(404);

        $this->assertSame($before, Transaction::count());
        $this->assertEquals(AgentProposedAction::STATUS_PROPOSED, $action->fresh()->status);
    }

    public function test_unknown_wallet_name_is_rejected_without_proposing(): void
    {
        $this->app->instance(BlockCollector::class, new BlockCollector());
        $tool = $this->app->make(RecordTransactionTool::class);
        $context = ToolContext::forUser($this->user, 'en', ['chat_session_id' => $this->session->id]);

        $before = AgentProposedAction::count();
        $out = $tool->handle(['amount' => 20, 'type' => 'expense', 'wallet_name' => 'Nonexistent'], $context);

        $this->assertArrayHasKey('error', $out);
        $this->assertSame($before, AgentProposedAction::count(), 'A failed validation must not persist a proposal.');
    }

    public function test_create_category_is_low_risk(): void
    {
        $tool = $this->app->make(\App\Ai\Tools\Write\CreateCategoryTool::class);
        $this->app->instance(BlockCollector::class, new BlockCollector());
        $context = ToolContext::forUser($this->user, 'en', ['chat_session_id' => $this->session->id]);

        $tool->handle(['name' => 'Groceries', 'type' => 'expense'], $context);

        $action = AgentProposedAction::latest("id")->firstOrFail();
        $this->assertEquals(AgentProposedAction::RISK_LOW, $action->risk);
        $this->assertEquals('category.create', $action->action_type);

        $this->actingAs($this->user)
            ->postJson("/api/v1/ai/chats/{$this->session->id}/actions/{$action->id}/confirm")
            ->assertStatus(200);

        $this->assertDatabaseHas('categories', [
            'user_id' => $this->user->id,
            'name' => 'Groceries',
            'type' => 'expense',
        ]);
    }

    public function test_proposed_action_includes_a_review_form_with_resolved_names(): void
    {
        $collector = new BlockCollector();
        $this->app->instance(BlockCollector::class, $collector);
        $tool = $this->app->make(RecordTransactionTool::class);
        $context = ToolContext::forUser($this->user, 'en', ['chat_session_id' => $this->session->id]);

        $tool->handle([
            'amount' => 20,
            'type' => 'expense',
            'wallet_id' => $this->wallet->id,
            'description' => 'coffee',
        ], $context);

        $block = $collector->all()[0];
        $this->assertSame('proposed_action', $block['type']);

        $fields = collect($block['fields']);
        // Editable schema: `value` is the raw editable value, `display` the label.
        $this->assertEquals('Cash', $fields->firstWhere('label', 'Wallet')['display']);
        $this->assertEquals($this->wallet->id, $fields->firstWhere('label', 'Wallet')['value']);
        $this->assertEquals('wallet', $fields->firstWhere('label', 'Wallet')['type']);
        $this->assertEquals('Expense', $fields->firstWhere('label', 'Type')['display']);
        $this->assertEquals('coffee', $fields->firstWhere('label', 'Description')['value']);
    }

    public function test_confirm_with_overrides_saves_edited_values(): void
    {
        $action = $this->propose(['amount' => 20, 'type' => 'expense', 'wallet_id' => $this->wallet->id]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/ai/chats/{$this->session->id}/actions/{$action->id}/confirm", [
                'overrides' => ['amount' => 35, 'description' => 'edited coffee'],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->user->id,
            'amount' => 35,
            'description' => 'edited coffee',
        ]);
    }

    public function test_confirm_override_cannot_target_another_users_wallet(): void
    {
        $action = $this->propose(['amount' => 20, 'type' => 'expense', 'wallet_id' => $this->wallet->id]);
        $intruderWallet = Wallet::factory()->create(['user_id' => User::factory()->create()->id]);
        $before = Transaction::count();

        $this->actingAs($this->user)
            ->postJson("/api/v1/ai/chats/{$this->session->id}/actions/{$action->id}/confirm", [
                'overrides' => ['wallet_id' => $intruderWallet->id],
            ])
            ->assertStatus(403);

        $this->assertSame($before, Transaction::count());
        $this->assertEquals(AgentProposedAction::STATUS_PROPOSED, $action->fresh()->status);
    }

    public function test_confirm_override_ignores_unlisted_keys(): void
    {
        $action = $this->propose(['amount' => 20, 'type' => 'expense', 'wallet_id' => $this->wallet->id]);
        $otherUser = User::factory()->create();

        $this->actingAs($this->user)
            ->postJson("/api/v1/ai/chats/{$this->session->id}/actions/{$action->id}/confirm", [
                'overrides' => ['user_id' => $otherUser->id, 'amount' => 22],
            ])
            ->assertStatus(200);

        // user_id override is dropped; the transaction belongs to the acting user.
        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->user->id,
            'amount' => 22,
        ]);
    }

    public function test_confirm_without_a_time_records_today_not_1970(): void
    {
        $action = $this->propose(['amount' => 20, 'type' => 'expense', 'wallet_id' => $this->wallet->id]);

        // An untouched "When" field arrives as an empty string; it must not be
        // recorded as the Unix epoch.
        $this->actingAs($this->user)
            ->postJson("/api/v1/ai/chats/{$this->session->id}/actions/{$action->id}/confirm", [
                'overrides' => ['datetime' => ''],
            ])
            ->assertStatus(200);

        $transaction = Transaction::latest('id')->firstOrFail();
        $this->assertNotNull($transaction->datetime);
        $this->assertEquals(now()->year, $transaction->datetime->year);
    }

    public function test_confirm_and_reject_mark_the_stored_block_done(): void
    {
        foreach (['confirm' => 'executed', 'reject' => 'rejected'] as $verb => $expected) {
            $action = $this->propose(['amount' => 20, 'type' => 'expense', 'wallet_id' => $this->wallet->id]);
            $message = $this->session->messages()->create([
                'role' => ChatMessage::ROLE_ASSISTANT,
                'status' => ChatMessage::STATUS_COMPLETED,
                'result' => ['source' => 'agent', 'blocks' => [
                    ['type' => 'proposed_action', 'id' => $action->id, 'status' => 'proposed'],
                ]],
            ]);
            $action->update(['chat_message_id' => $message->id]);

            $this->actingAs($this->user)
                ->postJson("/api/v1/ai/chats/{$this->session->id}/actions/{$action->id}/{$verb}")
                ->assertStatus(200);

            $this->assertSame($expected, $message->fresh()->result['blocks'][0]['status']);
        }
    }

    public function test_list_tools_return_only_owned_resources(): void
    {
        Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Food', 'type' => 'expense']);
        $intruder = User::factory()->create();
        Wallet::factory()->create(['user_id' => $intruder->id, 'name' => 'Theirs', 'currency' => 'USD']);

        $context = ToolContext::forUser($this->user);

        $wallets = $this->app->make(\App\Ai\Tools\Read\ListWalletsTool::class)->handle([], $context);
        $names = array_column($wallets['wallets'], 'name');
        $this->assertContains('Cash', $names);
        $this->assertNotContains('Theirs', $names);

        $categories = $this->app->make(\App\Ai\Tools\Read\ListCategoriesTool::class)->handle([], $context);
        $this->assertContains('Food', array_column($categories['categories'], 'name'));
    }

    public function test_record_transaction_by_wallet_id_without_category(): void
    {
        $this->app->instance(BlockCollector::class, new BlockCollector());
        $tool = $this->app->make(RecordTransactionTool::class);
        $context = ToolContext::forUser($this->user, 'en', ['chat_session_id' => $this->session->id]);

        // No category at all: a description like "coffee" must not require one.
        $out = $tool->handle([
            'amount' => 20,
            'type' => 'expense',
            'wallet_id' => $this->wallet->id,
            'description' => 'coffee',
        ], $context);

        $this->assertIsString($out);
        $action = AgentProposedAction::latest('id')->firstOrFail();
        $this->assertEquals($this->wallet->id, $action->payload['wallet_id']);
        $this->assertArrayNotHasKey('categories', $action->payload);
        $this->assertEquals('coffee', $action->payload['description']);
    }
}
