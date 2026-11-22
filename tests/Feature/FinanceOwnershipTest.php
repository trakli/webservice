<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_matching_user_and_workspace_ids_do_not_expose_shared_records_through_personal_apis(): void
    {
        $member = User::factory()->create();
        $space = $this->actingAs($member)->postJson('/api/v1/workspaces', ['name' => 'Shared budget'])
            ->assertCreated()->json('data');
        $user = User::find($space['id']) ?? User::factory()->create(['id' => $space['id']]);
        $walletId = $this->postJson('/api/v1/workspaces/' . $space['slug'] . '/wallets', [
            'name' => 'Shared cash', 'type' => 'cash', 'currency' => 'USD',
        ])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('wallets', ['id' => $walletId, 'owner_type' => Workspace::class, 'owner_id' => $user->id]);
        $this->actingAs($user)->getJson('/api/v1/wallets')->assertOk()->assertJsonMissing(['id' => $walletId]);
        $this->getJson('/api/v1/wallets/' . $walletId)->assertNotFound();
        $this->postJson('/api/v1/transactions', [
            'wallet_id' => $walletId, 'amount' => 5, 'type' => 'expense', 'datetime' => now()->toIso8601String(),
        ])->assertForbidden();
        $this->postJson('/api/v1/wallets', [
            'name' => 'Personal cash', 'type' => 'cash', 'currency' => 'USD',
            'owner_type' => Workspace::class, 'owner_id' => $space['id'],
        ])->assertCreated()->assertJsonPath('data.owner_type', User::class)
            ->assertJsonPath('data.owner_id', $user->id)->assertJsonPath('data.user_id', $user->id);
    }

    public function test_account_deletion_keeps_workspace_history_and_other_users_records(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $space = $this->actingAs($owner)->postJson('/api/v1/workspaces', ['name' => 'Shared budget'])
            ->assertCreated()->json('data');
        $shared = $this->postJson('/api/v1/workspaces/' . $space['slug'] . '/wallets', [
            'name' => 'Shared cash', 'type' => 'cash', 'currency' => 'USD',
        ])->assertCreated()->json('data.id');
        $personal = $this->postJson('/api/v1/wallets', [
            'name' => 'Private cash', 'type' => 'cash', 'currency' => 'USD',
        ])->assertCreated()->json('data.id');
        $theirs = $this->actingAs($other)->postJson('/api/v1/wallets', [
            'name' => 'Other cash', 'type' => 'cash', 'currency' => 'USD',
        ])->assertCreated()->json('data.id');
        $this->actingAs($owner)->deleteJson('/api/v1/account', ['confirm_delete' => true])->assertOk();
        $this->assertDatabaseMissing('wallets', ['id' => $personal]);
        $this->assertDatabaseHas('wallets', ['id' => $shared, 'owner_type' => Workspace::class, 'created_by_user_id' => null]);
        $this->assertDatabaseHas('wallets', ['id' => $theirs, 'owner_type' => User::class, 'owner_id' => $other->id]);
    }

    public function test_permanent_workspace_deletion_refuses_soft_deleted_financial_history(): void
    {
        $owner = User::factory()->create();
        $space = $this->actingAs($owner)->postJson('/api/v1/workspaces', ['name' => 'Shared budget'])
            ->assertCreated()->json('data');
        $base = '/api/v1/workspaces/' . $space['slug'];
        $wallet = $this->postJson($base . '/wallets', [
            'name' => 'Shared cash', 'type' => 'cash', 'currency' => 'USD',
        ])->assertCreated()->json('data.id');
        $this->deleteJson($base . '/wallets/' . $wallet)->assertOk();
        $this->assertSoftDeleted('wallets', ['id' => $wallet]);
        $this->expectException(\RuntimeException::class);
        Workspace::findOrFail($space['id'])->forceDelete();
    }

    public function test_workspace_reminders_notify_current_members_without_notifying_unrelated_users(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $space = $this->actingAs($owner)->postJson('/api/v1/workspaces', ['name' => 'Shared budget'])
            ->assertCreated()->json('data.id');
        $workspace = Workspace::findOrFail($space);
        $reminder = $workspace->reminders()->create([
            'title' => 'Shared rent', 'type' => 'custom', 'status' => 'active',
            'trigger_at' => now()->subMinute(), 'next_trigger_at' => now()->subMinute(),
        ]);
        $this->artisan('reminders:process')->assertSuccessful();
        $this->assertDatabaseHas('notifications', ['user_id' => $owner->id, 'title' => 'Shared rent']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $other->id, 'title' => 'Shared rent']);
        $this->assertSame('completed', $reminder->fresh()->status->value);
    }

    public function test_transaction_owner_cannot_differ_from_its_wallet_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $owner->id]);
        $this->expectException(\InvalidArgumentException::class);
        $other->transactions()->create(['wallet_id' => $wallet->id, 'amount' => 1, 'type' => 'expense']);
    }
}
