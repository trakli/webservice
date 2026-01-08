<?php

namespace Tests\Feature;

use App\Mail\InactivityReminderMail;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InactivityService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Whilesmart\ModelConfiguration\Enums\ConfigValueType;

class InactivityServiceTest extends TestCase
{
    use RefreshDatabase;

    private InactivityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $notificationService = new NotificationService(null);
        $this->service = new InactivityService($notificationService);
        Mail::fake();
    }

    public function test_sends_reminder_to_user_inactive_for_7_days()
    {
        $user = $this->createUserWithOldTransaction(8);

        $sent = $this->service->sendInactivityReminders();

        $this->assertEquals(1, $sent);
        Mail::assertQueued(InactivityReminderMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function test_sends_reminder_to_user_inactive_for_14_days()
    {
        $user = $this->createUserWithOldTransaction(14);

        $sent = $this->service->sendInactivityReminders();

        $this->assertEquals(1, $sent);
        Mail::assertQueued(InactivityReminderMail::class);
    }

    public function test_sends_reminder_to_user_inactive_for_30_days()
    {
        $user = $this->createUserWithOldTransaction(30);

        $sent = $this->service->sendInactivityReminders();

        $this->assertEquals(1, $sent);
        Mail::assertQueued(InactivityReminderMail::class);
    }

    public function test_does_not_send_reminder_to_recently_active_user()
    {
        $user = $this->createUserWithOldTransaction(3);

        $sent = $this->service->sendInactivityReminders();

        $this->assertEquals(0, $sent);
        Mail::assertNothingQueued();
    }

    public function test_does_not_send_reminder_to_user_who_opted_out()
    {
        $user = $this->createUserWithOldTransaction(10);
        $user->setConfigValue(InactivityService::CONFIG_INACTIVITY_REMINDERS_ENABLED, 'false', ConfigValueType::String);

        $sent = $this->service->sendInactivityReminders();

        $this->assertEquals(0, $sent);
        Mail::assertNothingQueued();
    }

    public function test_does_not_send_reminder_if_sent_recently()
    {
        $user = $this->createUserWithOldTransaction(10);
        $user->setConfigValue(
            InactivityService::CONFIG_LAST_REMINDER_SENT,
            now()->subDays(3)->toIso8601String(),
            ConfigValueType::String
        );

        $sent = $this->service->sendInactivityReminders();

        $this->assertEquals(0, $sent);
        Mail::assertNothingQueued();
    }

    public function test_sends_reminder_if_last_reminder_was_7_plus_days_ago()
    {
        $user = $this->createUserWithOldTransaction(14);
        $user->setConfigValue(
            InactivityService::CONFIG_LAST_REMINDER_SENT,
            now()->subDays(8)->toIso8601String(),
            ConfigValueType::String
        );
        $user->setConfigValue(InactivityService::CONFIG_INACTIVITY_REMINDER_COUNT, 1, ConfigValueType::Integer);

        $sent = $this->service->sendInactivityReminders();

        $this->assertEquals(1, $sent);
        Mail::assertQueued(InactivityReminderMail::class);
    }

    public function test_does_not_send_reminder_after_max_reminders_reached()
    {
        $user = $this->createUserWithOldTransaction(30);
        $user->setConfigValue(InactivityService::CONFIG_INACTIVITY_REMINDER_COUNT, 4, ConfigValueType::Integer);

        $sent = $this->service->sendInactivityReminders();

        $this->assertEquals(0, $sent);
        Mail::assertNothingQueued();
    }

    public function test_does_not_send_reminder_after_60_days_inactivity()
    {
        $user = $this->createUserWithOldTransaction(65);

        $sent = $this->service->sendInactivityReminders();

        $this->assertEquals(0, $sent);
        Mail::assertNothingQueued();
    }

    public function test_increments_reminder_count_after_sending()
    {
        $user = $this->createUserWithOldTransaction(10);

        $this->service->sendInactivityReminders();

        $this->assertEquals(1, (int) $user->fresh()->getConfigValue(
            InactivityService::CONFIG_INACTIVITY_REMINDER_COUNT
        ));
    }

    public function test_does_not_send_to_user_with_no_transactions()
    {
        User::factory()->create();

        $sent = $this->service->sendInactivityReminders();

        $this->assertEquals(0, $sent);
        Mail::assertNothingQueued();
    }

    public function test_reset_reminder_count_clears_tracking()
    {
        $user = $this->createUserWithOldTransaction(10);
        $user->setConfigValue(InactivityService::CONFIG_INACTIVITY_REMINDER_COUNT, 3, ConfigValueType::Integer);
        $user->setConfigValue(InactivityService::CONFIG_LAST_REMINDER_SENT, now()->toIso8601String(), ConfigValueType::String);

        $this->service->resetReminderCount($user);

        $this->assertEquals(0, (int) $user->getConfigValue(InactivityService::CONFIG_INACTIVITY_REMINDER_COUNT));
        $this->assertEmpty($user->getConfigValue(InactivityService::CONFIG_LAST_REMINDER_SENT));
    }

    private function createUserWithOldTransaction(int $daysAgo): User
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);

        Transaction::factory()->create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'created_at' => now()->subDays($daysAgo),
        ]);

        return $user;
    }
}
