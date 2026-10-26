<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Whilesmart\Entitlements\Contracts\Entitlements;

class WorkspaceFinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $member;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $response = $this->actingAs($this->owner)->postJson('/api/v1/workspaces', ['name' => 'Our family', 'kind' => 'family']);
        $response->assertCreated();
        $this->base = '/api/v1/workspaces/' . $response->json('data.slug');
        $invite = $this->postJson($this->base . '/members/invite', ['email' => $this->member->email, 'role' => 'member'])->assertCreated();
        $url = $invite->json('data.url');
        $token = basename($url);
        $this->actingAs($this->member)->postJson('/api/v1/workspaces/invitations/' . $token . '/accept')->assertOk();
    }

    private function personalWallet(User $user, string $name = 'Private account'): int
    {
        return $this->actingAs($user)->postJson('/api/v1/wallets', ['name' => $name, 'type' => 'cash', 'currency' => 'USD', 'balance' => 0])
            ->assertCreated()->json('data.id');
    }

    private function personalTransaction(User $user, int $wallet, string $description, int $amount): int
    {
        return $this->actingAs($user)->postJson('/api/v1/transactions', ['wallet_id' => $wallet, 'description' => $description,
            'amount' => $amount, 'type' => 'expense', 'datetime' => now()->toIso8601String()])->assertCreated()->json('data.id');
    }

    private function budget(): int
    {
        return $this->actingAs($this->owner)->postJson($this->base . '/budgets', [
            'name' => 'Household', 'amount' => 500, 'currency' => 'USD', 'period_type' => 'monthly',
            'start_date' => now()->startOfMonth()->toDateString(),
        ])->assertCreated()->json('data.id');
    }

    public function test_selected_transaction_hides_personal_wallet_and_unshared_spending(): void
    {
        $wallet = $this->personalWallet($this->member);
        $shared = $this->personalTransaction($this->member, $wallet, 'Shared groceries', 40);
        $this->personalTransaction($this->member, $wallet, 'Private purchase', 80);
        $this->postJson($this->base . '/shares/transactions', ['transaction_id' => $shared, 'confirmed' => true])->assertCreated();
        $this->actingAs($this->owner)->getJson($this->base . '/transactions')->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.description', 'Shared groceries')->assertJsonPath('data.data.0.wallet_id', null)
            ->assertJsonPath('data.data.0.wallet_name', null)->assertJsonMissing(['description' => 'Private purchase']);
        $response = $this->getJson($this->base . '/finance')->assertOk();
        $response->assertJsonCount(0, 'data.wallets');
    }

    public function test_overlapping_shares_count_once_and_source_edits_update_progress(): void
    {
        $wallet = $this->personalWallet($this->member);
        $transaction = $this->personalTransaction($this->member, $wallet, 'Food', 40);
        $this->postJson($this->base . '/shares/transactions', ['transaction_id' => $transaction, 'confirmed' => true])->assertCreated();
        $this->postJson($this->base . '/shares/wallets', ['wallet_id' => $wallet, 'confirmed' => true])->assertCreated();
        $this->budget();
        $this->getJson($this->base . '/finance')->assertJsonPath('data.budgets.0.progress.net_spent', 40);
        $this->actingAs($this->member)->putJson('/api/v1/transactions/' . $transaction, ['amount' => 65])->assertOk();
        $this->actingAs($this->owner)->getJson($this->base . '/finance')->assertJsonPath('data.budgets.0.progress.net_spent', 65);
        $this->getJson($this->base . '/transactions')->assertJsonCount(1, 'data.data');
    }

    public function test_admin_cannot_edit_shared_personal_originals(): void
    {
        $wallet = $this->personalWallet($this->member);
        $transaction = $this->personalTransaction($this->member, $wallet, 'Food', 40);
        $this->postJson($this->base . '/shares/wallets', ['wallet_id' => $wallet, 'confirmed' => true])->assertCreated();
        $this->actingAs($this->owner)->putJson($this->base . '/transactions/' . $transaction, ['amount' => 99])->assertForbidden();
        $this->deleteJson($this->base . '/transactions/' . $transaction)->assertForbidden();
        $this->assertDatabaseHas('transactions', ['id' => $transaction, 'amount' => 40]);
    }

    public function test_native_workspace_records_stay_out_of_personal_queries_and_survive_departure(): void
    {
        $wallet = $this->actingAs($this->owner)->postJson($this->base . '/wallets', [
            'name' => 'Household cash', 'type' => 'cash', 'currency' => 'USD', 'balance' => 100,
        ])->assertCreated()->json('data.id');
        $transaction = $this->actingAs($this->member)->postJson($this->base . '/transactions', [
            'wallet_id' => $wallet, 'amount' => 20, 'type' => 'expense', 'description' => 'Household expense',
            'datetime' => now()->toIso8601String(),
        ])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/transactions')->assertJsonMissing(['id' => $transaction, 'description' => 'Household expense']);
        $this->getJson('/api/v1/transactions/' . $transaction)->assertForbidden();
        $this->postJson($this->base . '/leave')->assertOk();
        $this->getJson($this->base . '/transactions')->assertForbidden();
        $this->actingAs($this->owner)->getJson($this->base . '/transactions')->assertJsonCount(1, 'data.data');
        $this->getJson($this->base . '/finance')->assertJsonPath('data.wallets.0.balance', 80);
    }

    public function test_members_cannot_manage_budgets_but_can_be_promoted(): void
    {
        $this->postJson($this->base . '/budgets', [])->assertForbidden();
        $this->actingAs($this->owner)->patchJson($this->base . '/members/' . $this->member->id, ['role' => 'admin'])->assertOk();
        $this->actingAs($this->member)->postJson($this->base . '/wallets', ['name' => 'Trip', 'type' => 'cash', 'currency' => 'USD'])->assertCreated();
        $this->patchJson($this->base . '/members/' . $this->owner->id, ['role' => 'member'])->assertUnprocessable();
    }

    public function test_removing_member_revokes_personal_shares(): void
    {
        $wallet = $this->personalWallet($this->member);
        $this->personalTransaction($this->member, $wallet, 'Food', 40);
        $this->postJson($this->base . '/shares/wallets', ['wallet_id' => $wallet, 'confirmed' => true])->assertCreated();
        $this->budget();
        $this->deleteJson($this->base . '/members/' . $this->member->id)->assertOk();
        $this->getJson($this->base . '/finance')->assertJsonCount(0, 'data.wallets')->assertJsonPath('data.budgets.0.progress.net_spent', 0);
    }

    public function test_outsider_and_foreign_targets_are_rejected(): void
    {
        $outsider = User::factory()->create();
        $wallet = $this->personalWallet($outsider);
        $this->getJson($this->base . '/finance')->assertForbidden()->assertJsonPath('success', false);
        $this->actingAs($this->owner)->postJson($this->base . '/shares/wallets', ['wallet_id' => $wallet, 'confirmed' => true])->assertNotFound();
        $this->postJson($this->base . '/budgets', ['name' => 'Bad target', 'amount' => 500, 'currency' => 'USD',
            'period_type' => 'monthly', 'start_date' => now()->toDateString(), 'targets' => [['type' => 'wallet', 'id' => $wallet]]])->assertUnprocessable();
    }

    public function test_workspace_creation_restrictions_use_generic_denials(): void
    {
        $entitlements = \Mockery::mock(Entitlements::class);
        $entitlements->shouldReceive('allows')->withArgs(fn ($owner, $feature) => $feature === 'shared_workspaces')->andReturn(false);
        $this->app->instance(Entitlements::class, $entitlements);
        $this->actingAs($this->owner)->getJson('/api/v1/workspaces')->assertOk()->assertJsonPath('data.can_create', false);
        $this->postJson('/api/v1/workspaces', ['name' => 'Another space'])->assertForbidden()
            ->assertJsonPath('message', 'You cannot create shared workspaces.')->assertJsonPath('success', false);
    }

    public function test_denied_workspace_writes_still_allow_reading_unsharing_and_leaving(): void
    {
        $wallet = $this->personalWallet($this->member);
        $this->postJson($this->base . '/shares/wallets', ['wallet_id' => $wallet, 'confirmed' => true])->assertCreated();
        $entitlements = \Mockery::mock(Entitlements::class);
        $entitlements->shouldReceive('allows')->withArgs(fn ($owner, $feature) => $owner instanceof \App\Models\Workspace && $feature === 'shared_workspaces')->andReturn(false);
        $this->app->instance(Entitlements::class, $entitlements);
        $this->getJson($this->base . '/finance')->assertOk();
        $this->postJson($this->base . '/transactions', [])->assertForbidden()->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Changes to this workspace are not allowed.');
        $this->actingAs($this->owner)->postJson($this->base . '/members/invite', ['email' => 'new@example.com', 'role' => 'member'])->assertForbidden();
        $this->actingAs($this->member)->deleteJson($this->base . '/shares/wallets/' . $wallet)->assertOk();
        $this->postJson($this->base . '/leave')->assertOk();
    }
    public function test_workspace_classification_does_not_change_personal_labels(): void
    {
        $wallet = $this->personalWallet($this->member);
        $transaction = $this->personalTransaction($this->member, $wallet, 'Groceries', 40);
        $this->postJson($this->base . '/shares/transactions', ['transaction_id' => $transaction, 'confirmed' => true])->assertCreated();
        $category = $this->actingAs($this->owner)->postJson($this->base . '/labels/categories', ['name' => 'Food', 'type' => 'expense'])->assertCreated()->json('data.id');
        $this->putJson($this->base . '/transactions/' . $transaction . '/classification', ['category_ids' => [$category], 'group_ids' => []])->assertOk();
        $this->assertDatabaseMissing('categorizables', ['category_id' => $category, 'categorizable_id' => $transaction]);
        $this->postJson($this->base . '/budgets', ['name' => 'Food cap', 'amount' => 100, 'currency' => 'USD',
            'period_type' => 'monthly', 'start_date' => now()->startOfMonth()->toDateString(),
            'targets' => [['type' => 'category', 'id' => $category]]])->assertCreated()->assertJsonPath('data.progress.net_spent', 40);
        $foreign = $this->actingAs($this->member)->postJson('/api/v1/categories', ['name' => 'Private food', 'type' => 'expense'])->assertCreated()->json('data.id');
        $this->actingAs($this->owner)->putJson($this->base . '/transactions/' . $transaction . '/classification', ['category_ids' => [$foreign], 'group_ids' => []])->assertUnprocessable();
    }

    public function test_refunds_reduce_workspace_budget_spending(): void
    {
        $wallet = $this->actingAs($this->owner)->postJson($this->base . '/wallets', [
            'name' => 'Household', 'type' => 'cash', 'currency' => 'USD',
        ])->assertCreated()->json('data.id');
        $this->postJson($this->base . '/transactions', ['wallet_id' => $wallet, 'amount' => 100,
            'type' => 'expense', 'datetime' => now()->toIso8601String()])->assertCreated();
        $this->postJson($this->base . '/transactions', ['wallet_id' => $wallet, 'amount' => 25,
            'type' => 'income', 'datetime' => now()->toIso8601String(), 'is_refund' => true])->assertCreated();
        $this->budget();
        $this->getJson($this->base . '/finance')->assertJsonPath('data.budgets.0.progress.net_spent', 75)
            ->assertJsonPath('data.budgets.0.progress.refunds', 25);
        $this->postJson($this->base . '/transactions', ['wallet_id' => $wallet, 'amount' => 5,
            'type' => 'expense', 'datetime' => now()->toIso8601String(), 'is_refund' => true])->assertUnprocessable();
        $this->getJson($this->base . '/finance')->assertJsonPath('data.wallets.0.balance', -75);
    }

    public function test_revoking_wallet_also_revokes_selected_entries_from_it(): void
    {
        $wallet = $this->personalWallet($this->member);
        $transaction = $this->personalTransaction($this->member, $wallet, 'Food', 40);
        $this->postJson($this->base . '/shares/transactions', ['transaction_id' => $transaction, 'confirmed' => true])->assertCreated();
        $this->postJson($this->base . '/shares/wallets', ['wallet_id' => $wallet, 'confirmed' => true])->assertCreated();
        $this->deleteJson($this->base . '/shares/wallets/' . $wallet)->assertOk();
        $this->getJson($this->base . '/transactions')->assertJsonCount(0, 'data.data');
        $this->getJson('/api/v1/transactions/' . $transaction)->assertOk();
    }

    public function test_closed_budget_history_survives_share_revocation(): void
    {
        Queue::fake()->except([\App\Jobs\CloseBudgetPeriodJob::class]);
        $wallet = $this->personalWallet($this->member);
        $transaction = $this->actingAs($this->member)->postJson('/api/v1/transactions', ['wallet_id' => $wallet, 'amount' => 40,
            'type' => 'expense', 'description' => 'Past spending', 'datetime' => now()->subMonth()->startOfMonth()->toIso8601String()])->assertCreated()->json('data.id');
        $this->postJson($this->base . '/shares/transactions', ['transaction_id' => $transaction, 'confirmed' => true])->assertCreated();
        $budget = $this->actingAs($this->owner)->postJson($this->base . '/budgets', ['name' => 'Last month', 'amount' => 100,
            'currency' => 'USD', 'period_type' => 'custom', 'start_date' => now()->subMonth()->startOfMonth()->toDateString(),
            'end_date' => now()->subMonth()->endOfMonth()->toDateString(), 'rollover_enabled' => true])->assertCreated()->json('data.id');
        $this->postJson($this->base . '/budgets/' . $budget . '/close-period')->assertOk();
        $this->actingAs($this->member)->deleteJson($this->base . '/shares/transactions/' . $transaction)->assertOk();
        $this->actingAs($this->owner)->postJson($this->base . '/budgets/' . $budget . '/close-period')->assertOk();
        $this->getJson($this->base . '/budgets/' . $budget . '/periods')->assertJsonPath('data.data.0.net_spent', '40.0000');
    }

    public function test_workspace_assistant_writes_only_on_confirmation_and_pins_the_workspace(): void
    {
        $chat = $this->actingAs($this->owner)->postJson('/api/v1/ai/chats', ['message' => 'Create a shared budget', 'defer_processing' => true])->assertStatus(202)->json('data.id');
        $tool = $this->app->make(\App\Ai\Tools\Write\ProposeWorkspaceChangeTool::class);
        $tool->handle(
            ['operation' => 'create_budget', 'workspace' => basename($this->base), 'name' => 'Assistant plan',
            'amount' => 100, 'currency' => 'USD', 'period_type' => 'monthly', 'start_date' => now()->toDateString()],
            \Whilesmart\Agents\ValueObjects\ToolContext::forUser($this->owner, 'en', ['chat_session_id' => $chat])
        );
        $action = \App\Models\AgentProposedAction::where('action_type', 'workspace.change')->latest('id')->firstOrFail();
        $this->assertDatabaseMissing('budgets', ['name' => 'Assistant plan']);
        $this->postJson('/api/v1/ai/chats/' . $chat . '/actions/' . $action->id . '/confirm', [
            'overrides' => ['workspace' => 'another-space'],
        ])->assertOk();
        $this->getJson($this->base . '/finance')->assertJsonPath('data.budgets.0.name', 'Assistant plan');
        $this->postJson('/api/v1/ai/chats/' . $chat . '/actions/' . $action->id . '/confirm');
        $this->assertSame(1, \App\Models\Budget::where('name', 'Assistant plan')->count());
    }
    public function test_workspace_assistant_confirmation_fails_after_membership_is_revoked(): void
    {
        $this->actingAs($this->owner)->patchJson($this->base . '/members/' . $this->member->id, ['role' => 'admin'])->assertOk();
        $chat = $this->actingAs($this->member)->postJson('/api/v1/ai/chats', [
            'message' => 'Create a shared budget', 'defer_processing' => true,
        ])->assertStatus(202)->json('data.id');
        $tool = $this->app->make(\App\Ai\Tools\Write\ProposeWorkspaceChangeTool::class);
        $tool->handle(
            ['operation' => 'create_budget', 'workspace' => basename($this->base), 'name' => 'Revoked proposal',
            'amount' => 100, 'currency' => 'USD', 'period_type' => 'monthly', 'start_date' => now()->toDateString()],
            \Whilesmart\Agents\ValueObjects\ToolContext::forUser($this->member, 'en', ['chat_session_id' => $chat])
        );
        $action = \App\Models\AgentProposedAction::where('action_type', 'workspace.change')->latest('id')->firstOrFail();
        $this->actingAs($this->owner)->deleteJson($this->base . '/members/' . $this->member->id)->assertOk();
        $this->actingAs($this->member)->postJson('/api/v1/ai/chats/' . $chat . '/actions/' . $action->id . '/confirm')->assertForbidden();
        $this->assertDatabaseMissing('budgets', ['name' => 'Revoked proposal']);
    }

    public function test_budget_alerts_only_reach_current_workspace_members(): void
    {
        $wallet = $this->actingAs($this->owner)->postJson($this->base . '/wallets', [
            'name' => 'Household', 'type' => 'cash', 'currency' => 'USD',
        ])->assertCreated()->json('data.id');
        $budget = $this->budget();
        $this->postJson($this->base . '/transactions', ['wallet_id' => $wallet, 'amount' => 450,
            'type' => 'expense', 'datetime' => now()->toIso8601String()])->assertCreated();
        $this->deleteJson($this->base . '/members/' . $this->member->id)->assertOk();
        $job = new \App\Jobs\RecomputeBudgetProgressJob($budget);
        $job->handle($this->app->make(\App\Services\BudgetProgressService::class));
        $job->handle($this->app->make(\App\Services\BudgetProgressService::class));
        $this->assertDatabaseHas('reminders', ['user_id' => $this->owner->id, 'remindable_id' => $budget]);
        $this->assertDatabaseMissing('reminders', ['user_id' => $this->member->id, 'remindable_id' => $budget]);
        $this->assertSame(1, \App\Models\Reminder::where('user_id', $this->owner->id)->where('remindable_id', $budget)->where('source', 'threshold')->count());
    }
    public function test_revoked_budget_target_stays_narrow_and_hides_the_personal_wallet_name(): void
    {
        $wallet = $this->personalWallet($this->member, 'Private savings name');
        $this->personalTransaction($this->member, $wallet, 'Food', 40);
        $this->postJson($this->base . '/shares/wallets', ['wallet_id' => $wallet, 'confirmed' => true])->assertCreated();
        $native = $this->actingAs($this->owner)->postJson($this->base . '/wallets', ['name' => 'Other spending', 'type' => 'cash', 'currency' => 'USD'])->assertCreated()->json('data.id');
        $this->postJson($this->base . '/transactions', ['wallet_id' => $native, 'amount' => 100, 'type' => 'expense', 'datetime' => now()->toIso8601String()])->assertCreated();
        $this->postJson($this->base . '/budgets', ['name' => 'Only the shared wallet', 'amount' => 200, 'currency' => 'USD',
            'period_type' => 'monthly', 'start_date' => now()->startOfMonth()->toDateString(),
            'targets' => [['type' => 'wallet', 'id' => $wallet]]])->assertCreated()->assertJsonPath('data.progress.net_spent', 40);
        $this->actingAs($this->member)->deleteJson($this->base . '/shares/wallets/' . $wallet)->assertOk();
        $this->actingAs($this->owner)->getJson($this->base . '/finance')->assertOk()
            ->assertJsonPath('data.budgets.0.progress.net_spent', 0)
            ->assertJsonPath('data.budgets.0.targets.0.name', 'Unshared wallet')
            ->assertJsonMissing(['name' => 'Private savings name']);
    }
}
