<?php

namespace Tests\Feature;

use App\Events\AccountDeleted;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
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
        $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/users?search=' . $this->user->email);

        $response->assertStatus(200);
    }

    public function test_admin_can_show_user()
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/users/' . $this->user->id);

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

        $response = $this->actingAs($this->admin)->deleteJson('/api/v1/admin/users/' . $this->user->id, [
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
