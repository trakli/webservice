<?php

namespace Tests\Feature;

use App\Events\AccountDeleted;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class UserCommandTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_user_show_displays_details()
    {
        $this->artisan('user', ['action' => 'show', 'identifier' => $this->user->email])
            ->assertSuccessful();
    }

    public function test_user_show_by_id()
    {
        $this->artisan('user', ['action' => 'show', 'identifier' => $this->user->id])
            ->assertSuccessful();
    }

    public function test_user_show_fails_for_unknown_user()
    {
        $this->artisan('user', ['action' => 'show', 'identifier' => 'nobody@test.com'])
            ->expectsOutput("User 'nobody@test.com' not found.");
    }

    public function test_user_delete_removes_user()
    {
        Event::fake([AccountDeleted::class]);

        $this->artisan('user', ['action' => 'delete', 'identifier' => $this->user->email])
            ->expectsConfirmation("Are you sure you want to delete {$this->user->email}?", 'yes')
            ->expectsQuestion('Reason for deletion', 'Testing')
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
        Event::assertDispatched(AccountDeleted::class, function ($event) {
            return $event->source === 'Admin CLI';
        });
    }

    public function test_user_delete_can_be_cancelled()
    {
        $this->artisan('user', ['action' => 'delete', 'identifier' => $this->user->email])
            ->expectsConfirmation("Are you sure you want to delete {$this->user->email}?", 'no')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_user_unknown_action()
    {
        $this->artisan('user', ['action' => 'foo', 'identifier' => $this->user->email])
            ->expectsOutput("Unknown action 'foo'. Use: show, delete.");
    }
}
