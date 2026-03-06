<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Whilesmart\Roles\Models\Role;
use Tests\TestCase;

class AdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_admin_grant_assigns_role()
    {
        $this->artisan('admin', ['action' => 'grant', 'identifier' => $this->user->email])
            ->assertSuccessful();

        $this->assertTrue($this->user->fresh()->hasRole('admin'));
    }

    public function test_admin_grant_warns_if_already_admin()
    {
        Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $this->user->assignRole('admin');

        $this->artisan('admin', ['action' => 'grant', 'identifier' => $this->user->email])
            ->expectsOutput("{$this->user->email} is already an admin.");
    }

    public function test_admin_grant_fails_for_unknown_user()
    {
        $this->artisan('admin', ['action' => 'grant', 'identifier' => 'nobody@test.com'])
            ->expectsOutput("User 'nobody@test.com' not found.");
    }

    public function test_admin_revoke_removes_role()
    {
        Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $this->user->assignRole('admin');

        $this->artisan('admin', ['action' => 'revoke', 'identifier' => $this->user->email])
            ->assertSuccessful();

        $this->assertFalse($this->user->fresh()->hasRole('admin'));
    }

    public function test_admin_revoke_warns_if_not_admin()
    {
        $this->artisan('admin', ['action' => 'revoke', 'identifier' => $this->user->email])
            ->expectsOutput("{$this->user->email} is not an admin.");
    }

    public function test_admin_list_shows_admins()
    {
        Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $this->user->assignRole('admin');

        $this->artisan('admin', ['action' => 'list'])
            ->assertSuccessful();
    }

    public function test_admin_list_warns_when_no_admins()
    {
        $this->artisan('admin', ['action' => 'list'])
            ->expectsOutput('No admin users found.');
    }

    public function test_admin_grant_by_id()
    {
        $this->artisan('admin', ['action' => 'grant', 'identifier' => $this->user->id])
            ->assertSuccessful();

        $this->assertTrue($this->user->fresh()->hasRole('admin'));
    }

    public function test_admin_unknown_action()
    {
        $this->artisan('admin', ['action' => 'foo', 'identifier' => $this->user->email])
            ->expectsOutput("Unknown action 'foo'. Use: grant, revoke, create, list.");
    }

    public function test_admin_grant_without_identifier()
    {
        $this->artisan('admin', ['action' => 'grant'])
            ->expectsOutput('Please provide a user email or ID.');
    }
}
