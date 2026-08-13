<?php

namespace Tests\Feature;

use App\Ai\BlockCollector;
use App\Models\AgentProposedAction;
use App\Models\Budget;
use App\Models\Category;
use App\Models\ChatSession;
use App\Models\Group;
use App\Models\Reminder;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Whilesmart\AgentActions\Enums\ActionStatus;
use Whilesmart\Agents\Registries\ToolRegistry;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * The write tools and read tools added for budgets, refunds, recurring rules,
 * reminders and groups, exercised the way a user reaches them: a tool proposes,
 * the user confirms over HTTP, and only then does anything change.
 */
class AgentModelCoverageTest extends TestCase
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

    private function tool(string $name)
    {
        return $this->app->make(ToolRegistry::class)->resolve($name);
    }

    /**
     * Run a write tool the way the agent does and return what it proposed.
     */
    private function propose(string $toolName, array $arguments, ?User $user = null): AgentProposedAction
    {
        $this->assertNull($this->runTool($toolName, $arguments, $user), 'The tool reported an error instead of proposing.');

        return AgentProposedAction::latest('id')->firstOrFail();
    }

    /**
     * Run a write tool, returning the error message when it refuses.
     */
    private function runTool(string $toolName, array $arguments, ?User $user = null): ?string
    {
        $this->app->instance(BlockCollector::class, new BlockCollector());

        $context = ToolContext::forUser($user ?? $this->user, 'en', ['chat_session_id' => $this->session->id]);
        $result = $this->tool($toolName)->handle($arguments, $context);

        return is_array($result) ? ($result['error'] ?? null) : null;
    }

    private function confirm(AgentProposedAction $action, array $payload = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user)
            ->postJson("/api/v1/ai/chats/{$this->session->id}/actions/{$action->id}/confirm", $payload);
    }

    private function read(string $toolName, ?User $user = null): array
    {
        $result = $this->tool($toolName)->handle([], ToolContext::forUser($user ?? $this->user));

        $this->assertIsArray($result, "Read tool {$toolName} refused: " . (is_string($result) ? $result : ''));

        return $result;
    }

    private function transaction(array $attributes = []): Transaction
    {
        return Transaction::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'amount' => 25,
            'type' => 'expense',
        ], $attributes));
    }

    // Budgets

    public function test_budget_is_only_created_on_confirm(): void
    {
        $action = $this->propose('create_budget', [
            'name' => 'Groceries',
            'amount' => 300,
            'currency' => 'USD',
            'period_type' => 'monthly',
        ]);

        $this->assertSame(0, Budget::count(), 'Proposing must not create a budget.');

        $this->confirm($action)->assertStatus(200);

        $this->assertSame(ActionStatus::Executed, $action->fresh()->status);
        $this->assertDatabaseHas('budgets', [
            'owner_id' => $this->user->id,
            'owner_type' => $this->user->getMorphClass(),
            'name' => 'Groceries',
            'currency' => 'USD',
            'period_type' => 'monthly',
        ]);
    }

    public function test_budget_targets_are_attached_on_confirm(): void
    {
        $category = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Food']);

        $action = $this->propose('create_budget', [
            'name' => 'Food budget',
            'amount' => 200,
            'currency' => 'USD',
            'period_type' => 'monthly',
            'targets' => [['type' => 'category', 'name' => 'Food']],
        ]);

        $this->confirm($action)->assertStatus(200);

        $budget = Budget::firstOrFail();
        $this->assertSame([$category->id], $budget->categories()->pluck('categories.id')->all());
    }

    public function test_budget_refuses_a_target_the_user_does_not_own(): void
    {
        $other = User::factory()->create();
        $theirs = Category::factory()->create(['user_id' => $other->id, 'name' => 'Theirs']);

        $error = $this->runTool('create_budget', [
            'name' => 'Sneaky',
            'amount' => 100,
            'currency' => 'USD',
            'period_type' => 'monthly',
            'targets' => [['type' => 'category', 'id' => $theirs->id]],
        ]);

        $this->assertNotNull($error);
        $this->assertStringContainsString('does not belong to you', $error);
        $this->assertSame(0, AgentProposedAction::count());
    }

    public function test_budget_confirm_rejects_an_edited_target_from_another_user(): void
    {
        Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Food']);
        $other = User::factory()->create();
        $theirs = Category::factory()->create(['user_id' => $other->id, 'name' => 'Theirs']);

        $action = $this->propose('create_budget', [
            'name' => 'Food budget',
            'amount' => 200,
            'currency' => 'USD',
            'period_type' => 'monthly',
            'targets' => [['type' => 'category', 'name' => 'Food']],
        ]);

        $this->confirm($action, ['overrides' => ['targets' => [['type' => 'category', 'id' => $theirs->id]]]])
            ->assertStatus(422);

        $this->assertSame(0, Budget::count());
    }

    public function test_budget_rejects_a_custom_period_without_an_end_date(): void
    {
        $error = $this->runTool('create_budget', [
            'name' => 'Trip',
            'amount' => 500,
            'currency' => 'USD',
            'period_type' => 'custom',
        ]);

        $this->assertStringContainsString('end date', (string) $error);
    }

    public function test_budget_confirm_is_idempotent(): void
    {
        $action = $this->propose('create_budget', [
            'name' => 'Groceries',
            'amount' => 300,
            'currency' => 'USD',
            'period_type' => 'monthly',
        ]);

        $this->confirm($action)->assertStatus(200);
        $this->confirm($action);

        $this->assertSame(1, Budget::count());
    }

    // Refunds

    public function test_refund_links_both_transactions_on_confirm(): void
    {
        $expense = $this->transaction(['type' => 'expense', 'description' => 'Jacket']);
        $income = $this->transaction(['type' => 'income', 'description' => 'Jacket returned']);

        $action = $this->propose('record_refund', [
            'refund_transaction_id' => $income->id,
            'original_transaction_id' => $expense->id,
        ]);

        $this->assertDatabaseCount('refunds', 0);

        $this->confirm($action)->assertStatus(200);

        $this->assertDatabaseHas('refunds', [
            'refund_transaction_id' => $income->id,
            'original_transaction_id' => $expense->id,
        ]);
        $this->assertTrue($income->fresh()->isRefund());
    }

    public function test_refund_must_be_an_income_transaction(): void
    {
        $expense = $this->transaction(['type' => 'expense']);

        $error = $this->runTool('record_refund', ['refund_transaction_id' => $expense->id]);

        $this->assertStringContainsString('income', (string) $error);
    }

    public function test_refund_refuses_another_users_transaction(): void
    {
        $other = User::factory()->create();
        $theirWallet = Wallet::factory()->create(['user_id' => $other->id]);
        $theirs = Transaction::factory()->create([
            'user_id' => $other->id,
            'wallet_id' => $theirWallet->id,
            'type' => 'income',
        ]);

        $error = $this->runTool('record_refund', ['refund_transaction_id' => $theirs->id]);

        $this->assertStringContainsString('not found', (string) $error);
        $this->assertSame(0, AgentProposedAction::count());
    }

    public function test_refund_confirm_is_idempotent(): void
    {
        $income = $this->transaction(['type' => 'income']);

        $action = $this->propose('record_refund', ['refund_transaction_id' => $income->id]);

        $this->confirm($action)->assertStatus(200);
        $this->confirm($action);

        $this->assertDatabaseCount('refunds', 1);
    }

    // Recurring rules

    public function test_recurring_rule_attaches_to_its_transaction_on_confirm(): void
    {
        $transaction = $this->transaction(['description' => 'Rent', 'datetime' => now()]);

        $action = $this->propose('create_recurring_rule', [
            'transaction_id' => $transaction->id,
            'recurrence_period' => 'monthly',
        ]);

        $this->assertDatabaseCount('recurring_transaction_rules', 0);

        $this->confirm($action)->assertStatus(200);

        $this->assertDatabaseHas('recurring_transaction_rules', [
            'transaction_id' => $transaction->id,
            'recurrence_period' => 'monthly',
            'recurrence_interval' => 1,
        ]);
    }

    public function test_recurring_rule_refuses_another_users_transaction(): void
    {
        $other = User::factory()->create();
        $theirWallet = Wallet::factory()->create(['user_id' => $other->id]);
        $theirs = Transaction::factory()->create([
            'user_id' => $other->id,
            'wallet_id' => $theirWallet->id,
        ]);

        $error = $this->runTool('create_recurring_rule', [
            'transaction_id' => $theirs->id,
            'recurrence_period' => 'monthly',
        ]);

        $this->assertStringContainsString('not found', (string) $error);
    }

    public function test_recurring_rule_refuses_a_transaction_that_already_repeats(): void
    {
        $transaction = $this->transaction(['datetime' => now()]);

        $action = $this->propose('create_recurring_rule', [
            'transaction_id' => $transaction->id,
            'recurrence_period' => 'monthly',
        ]);
        $this->confirm($action)->assertStatus(200);

        $error = $this->runTool('create_recurring_rule', [
            'transaction_id' => $transaction->id,
            'recurrence_period' => 'weekly',
        ]);

        $this->assertStringContainsString('already repeats', (string) $error);
    }

    // Generic create tool

    public function test_group_is_created_through_the_generic_write_tool(): void
    {
        $action = $this->propose('create_group', ['name' => 'Household']);

        $this->assertSame('group.create', $action->action_type);
        $this->assertSame(0, Group::count());

        $this->confirm($action)->assertStatus(200);

        $this->assertDatabaseHas('groups', ['user_id' => $this->user->id, 'name' => 'Household']);
    }

    public function test_reminder_is_created_through_the_generic_write_tool(): void
    {
        $action = $this->propose('create_reminder', [
            'title' => 'Pay rent',
            'trigger_at' => '2026-09-01T09:00:00Z',
        ]);

        $this->confirm($action)->assertStatus(200);

        $this->assertDatabaseHas('reminders', ['user_id' => $this->user->id, 'title' => 'Pay rent']);
    }

    /**
     * A resource whose creation a purpose-built tool covers must keep that
     * tool: a generic one registered under the same name would silently
     * replace it with something that cannot express the same create.
     */
    public function test_a_hand_written_write_tool_is_not_replaced_by_a_generic_one(): void
    {
        $bespoke = [
            'create_budget' => \App\Ai\Tools\Write\CreateBudgetTool::class,
            'record_refund' => \App\Ai\Tools\Write\RecordRefundTool::class,
            'create_recurring_rule' => \App\Ai\Tools\Write\CreateRecurringRuleTool::class,
            'create_wallet' => \App\Ai\Tools\Write\CreateWalletTool::class,
            'record_transaction' => \App\Ai\Tools\Write\RecordTransactionTool::class,
        ];

        foreach ($bespoke as $name => $class) {
            $this->assertInstanceOf($class, $this->tool($name));
        }
    }

    public function test_generic_write_tool_enforces_the_resources_own_rules(): void
    {
        $error = $this->runTool('create_group', ['name' => str_repeat('x', 300)]);

        $this->assertNotNull($error);
        $this->assertSame(0, AgentProposedAction::count());
    }

    public function test_generic_write_tool_ignores_fields_the_resource_does_not_expose(): void
    {
        $other = User::factory()->create();

        $action = $this->propose('create_group', ['name' => 'Mine', 'user_id' => $other->id]);

        $this->assertArrayNotHasKey('user_id', $action->payload);

        $this->confirm($action)->assertStatus(200);

        $this->assertDatabaseHas('groups', ['name' => 'Mine', 'user_id' => $this->user->id]);
        $this->assertDatabaseMissing('groups', ['name' => 'Mine', 'user_id' => $other->id]);
    }

    // Generated read tools

    public function test_read_tools_never_show_another_users_records(): void
    {
        $other = User::factory()->create();
        $otherWallet = Wallet::factory()->create(['user_id' => $other->id]);

        Group::factory()->create(['user_id' => $other->id, 'name' => 'Theirs']);
        Reminder::factory()->create(['user_id' => $other->id, 'title' => 'Theirs']);
        Budget::factory()->create(['owner_id' => $other->id, 'owner_type' => $other->getMorphClass()]);

        $theirTransaction = Transaction::factory()->create([
            'user_id' => $other->id,
            'wallet_id' => $otherWallet->id,
            'type' => 'income',
        ]);
        $theirTransaction->markAsRefund();

        foreach (['list_groups', 'list_reminders', 'list_budgets', 'list_refunds'] as $tool) {
            $this->assertSame(0, $this->read($tool)['count'], "{$tool} leaked another user's records.");
        }

        $this->assertSame(1, $this->read('list_groups', $other)['count']);
        $this->assertSame(1, $this->read('list_refunds', $other)['count']);
    }

    public function test_read_tools_hide_internal_columns(): void
    {
        Group::factory()->create(['user_id' => $this->user->id, 'name' => 'Mine']);

        $row = $this->read('list_groups')['rows'][0];

        $this->assertArrayNotHasKey('user_id', $row);
        $this->assertSame('Mine', $row['name']);
    }

    public function test_exchange_rates_are_readable_by_anyone(): void
    {
        \App\Models\ExchangeRate::create([
            'base_currency' => 'USD',
            'target_currency' => 'EUR',
            'rate' => 0.9,
        ]);

        $this->assertSame(1, $this->read('list_exchange_rates')['count']);
    }

    public function test_a_guest_reads_nothing_from_an_owned_resource(): void
    {
        Group::factory()->create(['user_id' => $this->user->id]);

        $result = $this->tool('list_groups')->handle([], ToolContext::guest());

        $this->assertIsString($result);
    }

    public function test_transactions_keep_their_hand_written_read_tool(): void
    {
        $names = $this->app->make(ToolRegistry::class)->names();

        $this->assertNotContains('list_transactions', $names);
        $this->assertContains('list_wallets', $names);
        $this->assertContains('list_transfers', $names);
    }

    public function test_the_harness_offers_every_generated_read_tool(): void
    {
        $harness = $this->app->make(\App\Ai\Harnesses\TrakliHarness::class);
        $names = $harness->toolNames();

        foreach (['list_transfers', 'list_budgets', 'list_refunds', 'list_reminders', 'list_groups'] as $tool) {
            $this->assertContains($tool, $names);
        }

        $this->assertSame(array_unique($names), $names, 'The harness lists a tool twice.');
    }
}
