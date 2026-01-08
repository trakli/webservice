<?php

namespace Tests\Feature;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_user_can_create_reminder()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/reminders', [
            'title' => 'Track daily expenses',
            'description' => 'Remember to log your spending',
            'type' => 'daily_tracking',
            'trigger_at' => now()->addHour()->toIso8601String(),
            'timezone' => 'UTC',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'title',
                'description',
                'type',
                'status',
                'trigger_at',
                'timezone',
            ],
        ]);
        $this->assertEquals('Track daily expenses', $response->json('data.title'));
        $this->assertEquals('active', $response->json('data.status'));
    }

    public function test_user_can_create_recurring_reminder_with_rrule()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/reminders', [
            'title' => 'Weekly review',
            'type' => 'weekly_review',
            'trigger_at' => now()->toIso8601String(),
            'repeat_rule' => 'FREQ=WEEKLY;BYDAY=SU;BYHOUR=20;BYMINUTE=0',
            'timezone' => 'America/New_York',
        ]);

        $response->assertStatus(201);
        $this->assertEquals('FREQ=WEEKLY;BYDAY=SU;BYHOUR=20;BYMINUTE=0', $response->json('data.repeat_rule'));
        $this->assertNotNull($response->json('data.next_trigger_at'));
    }

    public function test_user_can_create_reminder_with_client_id()
    {
        $clientId = '245cb3df-df3a-428b-a908-e5f74b8d58a4:245cb3df-df3a-428b-a908-e5f74b8d58a5';

        $response = $this->actingAs($this->user)->postJson('/api/v1/reminders', [
            'title' => 'Monthly summary',
            'type' => 'monthly_summary',
            'client_id' => $clientId,
        ]);

        $response->assertStatus(201);
        $this->assertNotNull($response->json('data.client_generated_id'));
    }

    public function test_user_can_list_their_reminders()
    {
        Reminder::factory()->count(3)->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/reminders');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data.data'));
    }

    public function test_user_can_filter_reminders_by_status()
    {
        Reminder::factory()->create(['user_id' => $this->user->id, 'status' => ReminderStatus::ACTIVE]);
        Reminder::factory()->create(['user_id' => $this->user->id, 'status' => ReminderStatus::PAUSED]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/reminders?status=active');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_user_can_view_single_reminder()
    {
        $reminder = Reminder::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/reminders/{$reminder->id}");

        $response->assertStatus(200);
        $this->assertEquals($reminder->id, $response->json('data.id'));
    }

    public function test_user_cannot_view_another_users_reminder()
    {
        $otherUser = User::factory()->create();
        $reminder = Reminder::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/reminders/{$reminder->id}");

        $response->assertStatus(404);
    }

    public function test_user_can_update_reminder()
    {
        $reminder = Reminder::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Old title',
        ]);

        $response = $this->actingAs($this->user)->putJson("/api/v1/reminders/{$reminder->id}", [
            'title' => 'New title',
            'description' => 'Updated description',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('New title', $response->json('data.title'));
        $this->assertEquals('Updated description', $response->json('data.description'));
    }

    public function test_user_can_delete_reminder()
    {
        $reminder = Reminder::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->deleteJson("/api/v1/reminders/{$reminder->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('reminders', ['id' => $reminder->id]);
    }

    public function test_user_cannot_delete_another_users_reminder()
    {
        $otherUser = User::factory()->create();
        $reminder = Reminder::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($this->user)->deleteJson("/api/v1/reminders/{$reminder->id}");

        $response->assertStatus(404);
    }

    public function test_user_can_snooze_reminder()
    {
        $reminder = Reminder::factory()->create(['user_id' => $this->user->id]);
        $snoozeUntil = now()->addHours(2)->toIso8601String();

        $response = $this->actingAs($this->user)->postJson("/api/v1/reminders/{$reminder->id}/snooze", [
            'until' => $snoozeUntil,
        ]);

        $response->assertStatus(200);
        $this->assertEquals('snoozed', $response->json('data.status'));
        $this->assertNotNull($response->json('data.snoozed_until'));
    }

    public function test_user_can_pause_reminder()
    {
        $reminder = Reminder::factory()->create([
            'user_id' => $this->user->id,
            'status' => ReminderStatus::ACTIVE,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/reminders/{$reminder->id}/pause");

        $response->assertStatus(200);
        $this->assertEquals('paused', $response->json('data.status'));
    }

    public function test_user_can_resume_reminder()
    {
        $reminder = Reminder::factory()->create([
            'user_id' => $this->user->id,
            'status' => ReminderStatus::PAUSED,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/reminders/{$reminder->id}/resume");

        $response->assertStatus(200);
        $this->assertEquals('active', $response->json('data.status'));
    }

    public function test_reminder_requires_title()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/reminders', [
            'type' => 'custom',
        ]);

        $response->assertStatus(422);
    }

    public function test_reminder_validates_timezone()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/reminders', [
            'title' => 'Test reminder',
            'timezone' => 'Invalid/Timezone',
        ]);

        $response->assertStatus(422);
    }

    public function test_reminder_validates_type()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/reminders', [
            'title' => 'Test reminder',
            'type' => 'invalid_type',
        ]);

        $response->assertStatus(422);
    }
}
