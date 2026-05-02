<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_user_can_list_notifications()
    {
        Notification::factory()->count(5)->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/notifications');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'data',
                'unread_count',
            ],
        ]);
        $this->assertCount(5, $response->json('data.data'));
    }

    public function test_user_can_filter_unread_notifications()
    {
        Notification::factory()->count(3)->create(['user_id' => $this->user->id, 'read_at' => null]);
        Notification::factory()->count(2)->create(['user_id' => $this->user->id, 'read_at' => now()]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/notifications?unread_only=true');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data.data'));
    }

    public function test_user_can_get_unread_count()
    {
        Notification::factory()->count(4)->create(['user_id' => $this->user->id, 'read_at' => null]);
        Notification::factory()->count(2)->create(['user_id' => $this->user->id, 'read_at' => now()]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/notifications/unread-count');

        $response->assertStatus(200);
        $this->assertEquals(4, $response->json('data.count'));
    }

    public function test_user_can_view_single_notification()
    {
        $notification = Notification::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/notifications/{$notification->id}");

        $response->assertStatus(200);
        $this->assertEquals($notification->id, $response->json('data.id'));
    }

    public function test_user_cannot_view_another_users_notification()
    {
        $otherUser = User::factory()->create();
        $notification = Notification::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/notifications/{$notification->id}");

        $response->assertStatus(404);
    }

    public function test_user_can_mark_notification_as_read()
    {
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'read_at' => null,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.read_at'));
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_as_read_uses_supplied_read_at_timestamp()
    {
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'read_at' => null,
        ]);

        $readAt = now()->subHours(3);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/notifications/{$notification->id}/read",
            ['read_at' => $readAt->toIso8601String()],
        );

        $response->assertStatus(200);
        $this->assertEquals(
            $readAt->startOfSecond()->timestamp,
            $notification->fresh()->read_at->timestamp,
        );
    }

    public function test_mark_as_read_with_client_id_only_does_not_mark_read()
    {
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'read_at' => null,
        ]);

        $deviceId = '245cb3df-df3a-428b-a908-e5f74b8d58a4';
        $randomId = '245cb3df-df3a-428b-a908-e5f74b8d58a5';

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/notifications/{$notification->id}/read",
            ['client_id' => "$deviceId:$randomId"],
        );

        $response->assertStatus(200);
        $this->assertNull($notification->fresh()->read_at);
        $this->assertEquals($randomId, $notification->fresh()->syncState->client_generated_id);
    }

    public function test_mark_as_read_with_client_id_and_read_at_sets_both()
    {
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'read_at' => null,
        ]);

        $deviceId = '245cb3df-df3a-428b-a908-e5f74b8d58a4';
        $randomId = '245cb3df-df3a-428b-a908-e5f74b8d58a6';
        $readAt = now()->subMinutes(10);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/notifications/{$notification->id}/read",
            [
                'client_id' => "$deviceId:$randomId",
                'read_at' => $readAt->toIso8601String(),
            ],
        );

        $response->assertStatus(200);
        $fresh = $notification->fresh();
        $this->assertNotNull($fresh->read_at);
        $this->assertEquals($randomId, $fresh->syncState->client_generated_id);
    }

    public function test_mark_as_read_does_not_overwrite_existing_read_at()
    {
        $originalReadAt = now()->subDays(2)->startOfSecond();
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'read_at' => $originalReadAt,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/notifications/{$notification->id}/read",
            ['read_at' => now()->toIso8601String()],
        );

        $response->assertStatus(200);
        $this->assertEquals(
            $originalReadAt->timestamp,
            $notification->fresh()->read_at->timestamp,
        );
    }

    public function test_mark_as_read_rejects_invalid_client_id()
    {
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'read_at' => null,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/notifications/{$notification->id}/read",
            ['client_id' => 'not-a-valid-client-id'],
        );

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'must be in the format',
            $response->json('errors.client_id.0'),
        );
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_mark_as_read_rejects_invalid_read_at()
    {
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'read_at' => null,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/notifications/{$notification->id}/read",
            ['read_at' => 'not-a-date'],
        );

        $response->assertStatus(422);
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_user_can_mark_all_notifications_as_read()
    {
        Notification::factory()->count(5)->create([
            'user_id' => $this->user->id,
            'read_at' => null,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/notifications/read-all');

        $response->assertStatus(200);

        $unreadCount = Notification::where('user_id', $this->user->id)
            ->whereNull('read_at')
            ->count();
        $this->assertEquals(0, $unreadCount);
    }

    public function test_user_can_delete_notification()
    {
        $notification = Notification::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->deleteJson("/api/v1/notifications/{$notification->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    public function test_user_cannot_delete_another_users_notification()
    {
        $otherUser = User::factory()->create();
        $notification = Notification::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($this->user)->deleteJson("/api/v1/notifications/{$notification->id}");

        $response->assertStatus(404);
    }

    public function test_notifications_are_ordered_by_created_at_desc()
    {
        $old = Notification::factory()->create([
            'user_id' => $this->user->id,
            'created_at' => now()->subDays(2),
        ]);
        $new = Notification::factory()->create([
            'user_id' => $this->user->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/notifications');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertEquals($new->id, $data[0]['id']);
        $this->assertEquals($old->id, $data[1]['id']);
    }

    public function test_notifications_list_respects_limit()
    {
        Notification::factory()->count(10)->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/notifications?limit=5');

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('data.data'));
    }

    public function test_unread_count_only_counts_users_notifications()
    {
        Notification::factory()->count(3)->create(['user_id' => $this->user->id, 'read_at' => null]);

        $otherUser = User::factory()->create();
        Notification::factory()->count(5)->create(['user_id' => $otherUser->id, 'read_at' => null]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/notifications/unread-count');

        $response->assertStatus(200);
        $this->assertEquals(3, $response->json('data.count'));
    }

    public function test_notification_includes_sync_attributes()
    {
        $notification = Notification::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/notifications/{$notification->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'last_synced_at',
                'client_generated_id',
            ],
        ]);
    }

    public function test_notifications_list_returns_last_sync()
    {
        Notification::factory()->count(3)->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/notifications');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'last_sync',
                'data',
            ],
        ]);
    }

    public function test_notifications_can_be_filtered_by_synced_since()
    {
        $old = Notification::factory()->create([
            'user_id' => $this->user->id,
            'updated_at' => now()->subDays(5),
        ]);
        $new = Notification::factory()->create([
            'user_id' => $this->user->id,
            'updated_at' => now()->subDay(),
        ]);

        $syncedSince = urlencode(now()->subDays(3)->toIso8601String());
        $response = $this->actingAs($this->user)->getJson("/api/v1/notifications?synced_since={$syncedSince}");

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals($new->id, $data[0]['id']);
    }

    public function test_synced_since_with_invalid_date_returns_error()
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/notifications?synced_since=invalid-date');

        $response->assertStatus(422);
    }
}
