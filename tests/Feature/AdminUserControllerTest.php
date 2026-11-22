<?php

namespace Tests\Feature;

use App\Events\AccountDeleted;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Whilesmart\AgentMetrics\Facades\TokenMeter;
use Whilesmart\Roles\Models\Role;

class AdminUserControllerTest extends TestCase
{
    use RefreshDatabase;

    private $admin;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $this->admin->assignRole('admin');

        $this->user = User::factory()->create();
    }

    public function test_admin_can_list_users()
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/users');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_admin_can_search_users()
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/users?search='.$this->user->email);

        $response->assertStatus(200);
    }

    public function test_admin_can_paginate_and_filter_users_by_join_date(): void
    {
        User::factory()->count(6)->create(['created_at' => '2026-08-10 12:00:00']);
        User::factory()->count(2)->create(['created_at' => '2026-08-11 12:00:00']);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users?joined_on=2026-08-10&per_page=5&page=2');

        $response->assertOk()
            ->assertJsonPath('data.current_page', 2)
            ->assertJsonPath('data.per_page', 5)
            ->assertJsonPath('data.total', 6)
            ->assertJsonCount(1, 'data.data');
    }

    public function test_admin_user_list_includes_existing_activity_data(): void
    {
        $token = $this->user->createToken('admin-insight')->accessToken;
        $token->forceFill(['last_used_at' => '2026-08-18 12:34:00'])->save();
        Transaction::factory()->withUserAndWallet($this->user)->create([
            'datetime' => '2026-08-17 10:00:00',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users?search='.$this->user->email);

        $response->assertOk()
            ->assertJsonPath('data.data.0.last_seen_at', '2026-08-18 12:34:00')
            ->assertJsonPath('data.data.0.last_transaction_at', '2026-08-17 10:00:00');
    }

    public function test_admin_user_list_and_detail_include_token_usage(): void
    {
        TokenMeter::record($this->user, 'gemini', 'gemini-flash-latest', [
            'prompt_tokens' => 120,
            'completion_tokens' => 30,
        ]);

        $list = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users?search='.$this->user->email);
        $detail = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/users/'.$this->user->id);

        $list->assertOk()->assertJsonPath('data.data.0.tokens_used', 150);
        $detail->assertOk()->assertJsonPath('data.user.tokens_used', 150);
    }

    public function test_admin_can_show_user()
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/users/'.$this->user->id);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_admin_show_returns_404_for_unknown_user()
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/users/99999');

        $response->assertStatus(404);
    }

    public function test_admin_can_delete_user()
    {
        Event::fake([AccountDeleted::class]);

        $response = $this->actingAs($this->admin)->deleteJson('/api/v1/admin/users/'.$this->user->id, [
            'reason' => 'Policy violation',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'User deleted successfully.']);

        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
        Event::assertDispatched(AccountDeleted::class, function ($event) {
            return $event->source === 'Admin';
        });
    }

    public function test_admin_delete_returns_404_for_unknown_user()
    {
        $response = $this->actingAs($this->admin)->deleteJson('/api/v1/admin/users/99999');

        $response->assertStatus(404);
    }

    public function test_non_admin_cannot_access_admin_endpoints()
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/admin/users');

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_access_admin_endpoints()
    {
        $response = $this->getJson('/api/v1/admin/users');

        $response->assertStatus(401);
    }
}
