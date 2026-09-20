<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Whilesmart\Roles\Models\Role;

class FeedbackControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_submits_feedback_and_can_read_its_status(): void
    {
        $user = User::factory()->create();

        $created = $this->actingAs($user)->postJson('/api/v1/feedback', [
            'type' => 'feature',
            'subject' => 'Budgets',
            'message' => 'Please add shared budgets.',
        ]);

        $created->assertCreated()->assertJsonPath('data.status', 'new');
        $this->actingAs($user)->getJson('/api/v1/feedback')
            ->assertOk()
            ->assertJsonPath('data.0.message', 'Please add shared budgets.');
    }

    public function test_admin_triages_feedback(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create();
        Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $admin->assignRole('admin');

        $id = $this->actingAs($user)->postJson('/api/v1/feedback', [
            'type' => 'bug',
            'message' => 'The total does not update.',
        ])->json('data.id');

        $this->actingAs($admin)->getJson('/api/v1/admin/feedback')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $id);
        $this->actingAs($admin)->patchJson("/api/v1/admin/feedback/{$id}", [
            'status' => 'triaged',
        ])->assertOk()->assertJsonPath('data.status', 'triaged');
    }

    public function test_regular_user_cannot_open_admin_feedback(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/admin/feedback')
            ->assertForbidden();
    }
}
