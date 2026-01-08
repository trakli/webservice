<?php

namespace Tests\Feature;

use App\Enums\ReminderStatus;
use App\Models\Notification;
use App\Models\Reminder;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\ReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReminderServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReminderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $notificationService = new NotificationService(null);
        $this->service = new ReminderService($notificationService);
    }

    public function test_processes_due_reminders()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->create([
            'user_id' => $user->id,
            'status' => ReminderStatus::ACTIVE,
            'next_trigger_at' => now()->subMinutes(5),
        ]);

        $processed = $this->service->processDueReminders();

        $this->assertEquals(1, $processed);
    }

    public function test_creates_notification_when_processing_reminder()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->create([
            'user_id' => $user->id,
            'title' => 'Test reminder',
            'status' => ReminderStatus::ACTIVE,
            'next_trigger_at' => now()->subMinutes(5),
        ]);

        $this->service->processDueReminders();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'title' => 'Test reminder',
            'type' => 'reminder',
        ]);
    }

    public function test_updates_last_triggered_at()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->create([
            'user_id' => $user->id,
            'status' => ReminderStatus::ACTIVE,
            'next_trigger_at' => now()->subMinutes(5),
            'last_triggered_at' => null,
        ]);

        $this->service->processDueReminders();

        $reminder->refresh();
        $this->assertNotNull($reminder->last_triggered_at);
    }

    public function test_completes_non_recurring_reminder_after_trigger()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->create([
            'user_id' => $user->id,
            'status' => ReminderStatus::ACTIVE,
            'next_trigger_at' => now()->subMinutes(5),
            'repeat_rule' => null,
        ]);

        $this->service->processDueReminders();

        $reminder->refresh();
        $this->assertEquals(ReminderStatus::COMPLETED, $reminder->status);
    }

    public function test_calculates_next_trigger_for_recurring_reminder()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->create([
            'user_id' => $user->id,
            'status' => ReminderStatus::ACTIVE,
            'trigger_at' => now()->subDay(),
            'next_trigger_at' => now()->subMinutes(5),
            'repeat_rule' => 'FREQ=DAILY;BYHOUR=20;BYMINUTE=0',
        ]);

        $this->service->processDueReminders();

        $reminder->refresh();
        $this->assertEquals(ReminderStatus::ACTIVE, $reminder->status);
        $this->assertNotNull($reminder->next_trigger_at);
        $this->assertTrue($reminder->next_trigger_at->isFuture());
    }

    public function test_does_not_process_paused_reminders()
    {
        $user = User::factory()->create();
        Reminder::factory()->create([
            'user_id' => $user->id,
            'status' => ReminderStatus::PAUSED,
            'next_trigger_at' => now()->subMinutes(5),
        ]);

        $processed = $this->service->processDueReminders();

        $this->assertEquals(0, $processed);
        $this->assertEquals(0, Notification::count());
    }

    public function test_does_not_process_future_reminders()
    {
        $user = User::factory()->create();
        Reminder::factory()->create([
            'user_id' => $user->id,
            'status' => ReminderStatus::ACTIVE,
            'next_trigger_at' => now()->addHour(),
        ]);

        $processed = $this->service->processDueReminders();

        $this->assertEquals(0, $processed);
    }

    public function test_does_not_process_reminders_without_next_trigger_at()
    {
        $user = User::factory()->create();
        Reminder::factory()->create([
            'user_id' => $user->id,
            'status' => ReminderStatus::ACTIVE,
            'next_trigger_at' => null,
        ]);

        $processed = $this->service->processDueReminders();

        $this->assertEquals(0, $processed);
    }

    public function test_processes_multiple_due_reminders()
    {
        $user = User::factory()->create();
        Reminder::factory()->count(3)->create([
            'user_id' => $user->id,
            'status' => ReminderStatus::ACTIVE,
            'next_trigger_at' => now()->subMinutes(5),
        ]);

        $processed = $this->service->processDueReminders();

        $this->assertEquals(3, $processed);
        $this->assertEquals(3, Notification::count());
    }

    public function test_notification_contains_reminder_data()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->create([
            'user_id' => $user->id,
            'title' => 'Test title',
            'description' => 'Test description',
            'type' => 'daily_tracking',
            'status' => ReminderStatus::ACTIVE,
            'next_trigger_at' => now()->subMinutes(5),
        ]);

        $this->service->processDueReminders();

        $notification = Notification::first();
        $this->assertEquals('Test title', $notification->title);
        $this->assertEquals('Test description', $notification->body);
        $this->assertEquals($reminder->id, $notification->data['reminder_id']);
        $this->assertEquals('daily_tracking', $notification->data['reminder_type']);
    }
}
