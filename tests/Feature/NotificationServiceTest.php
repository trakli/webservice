<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Mail\GenericMail;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NotificationService(null);
        Mail::fake();
    }

    public function test_sends_inapp_notification()
    {
        $user = User::factory()->create();

        $notification = $this->service->sendInApp(
            $user,
            NotificationType::SYSTEM,
            'Test Title',
            'Test Body',
            ['key' => 'value']
        );

        $this->assertNotNull($notification);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'title' => 'Test Title',
            'body' => 'Test Body',
        ]);
    }

    public function test_sends_email_notification()
    {
        $user = User::factory()->create();

        $result = $this->service->sendEmail($user, 'Test Subject', 'Test Body');

        $this->assertTrue($result);
        Mail::assertQueued(GenericMail::class);
    }

    public function test_sends_email_with_custom_mailable()
    {
        $user = User::factory()->create();
        $mailable = new GenericMail('Custom Subject', 'Custom Body');

        $result = $this->service->sendEmail($user, 'Ignored', 'Ignored', $mailable);

        $this->assertTrue($result);
        Mail::assertQueued(GenericMail::class);
    }

    public function test_send_dispatches_to_all_channels()
    {
        $user = User::factory()->create();

        $results = $this->service->send(
            $user,
            NotificationType::SYSTEM,
            'Multi Channel',
            'Test message',
            ['data' => 'test'],
            null,
            ['inapp', 'email']
        );

        $this->assertNotNull($results['inapp']);
        $this->assertTrue($results['email']);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'title' => 'Multi Channel',
        ]);
        Mail::assertQueued(GenericMail::class);
    }

    public function test_respects_channel_preferences()
    {
        $user = User::factory()->create();
        $this->service->setChannelPreference($user, 'email', false);

        $results = $this->service->send(
            $user,
            NotificationType::SYSTEM,
            'Test',
            'Test message',
            [],
            null,
            ['inapp', 'email']
        );

        $this->assertNotNull($results['inapp']);
        $this->assertNull($results['email']);
        Mail::assertNothingQueued();
    }

    public function test_respects_notification_type_preferences()
    {
        $user = User::factory()->create();
        $this->service->setTypePreference($user, 'reminders', false);

        $results = $this->service->send(
            $user,
            NotificationType::REMINDER,
            'Test Reminder',
            'Test message'
        );

        $this->assertNull($results['inapp']);
        $this->assertNull($results['email']);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $user->id,
        ]);
    }

    public function test_get_preferences_returns_all_settings()
    {
        $user = User::factory()->create();

        $preferences = $this->service->getPreferences($user);

        $this->assertArrayHasKey('channels', $preferences);
        $this->assertArrayHasKey('types', $preferences);
        $this->assertTrue($preferences['channels']['email']);
        $this->assertTrue($preferences['channels']['push']);
        $this->assertTrue($preferences['channels']['inapp']);
    }

    public function test_channel_enabled_by_default()
    {
        $user = User::factory()->create();

        $this->assertTrue($this->service->isChannelEnabled($user, 'email'));
        $this->assertTrue($this->service->isChannelEnabled($user, 'push'));
        $this->assertTrue($this->service->isChannelEnabled($user, 'inapp'));
    }

    public function test_can_disable_channel()
    {
        $user = User::factory()->create();
        $this->service->setChannelPreference($user, 'push', false);

        $this->assertFalse($this->service->isChannelEnabled($user, 'push'));
        $this->assertTrue($this->service->isChannelEnabled($user, 'email'));
    }

    public function test_notification_type_enabled_by_default()
    {
        $user = User::factory()->create();

        $this->assertTrue($this->service->isNotificationTypeEnabled($user, NotificationType::REMINDER));
        $this->assertTrue($this->service->isNotificationTypeEnabled($user, NotificationType::SYSTEM));
        $this->assertTrue($this->service->isNotificationTypeEnabled($user, NotificationType::ALERT));
    }

    public function test_can_disable_notification_type()
    {
        $user = User::factory()->create();
        $this->service->setTypePreference($user, 'insights', false);

        $this->assertFalse($this->service->isNotificationTypeEnabled($user, NotificationType::SYSTEM));
        $this->assertTrue($this->service->isNotificationTypeEnabled($user, NotificationType::REMINDER));
    }

    public function test_invalid_channel_throws_exception()
    {
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->setChannelPreference($user, 'invalid', true);
    }

    public function test_invalid_type_throws_exception()
    {
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->setTypePreference($user, 'invalid', true);
    }

    public function test_send_only_specified_channels()
    {
        $user = User::factory()->create();

        $results = $this->service->send(
            $user,
            NotificationType::SYSTEM,
            'Test',
            'Test message',
            [],
            null,
            ['inapp']
        );

        $this->assertNotNull($results['inapp']);
        $this->assertNull($results['email']);
        Mail::assertNothingQueued();
    }

    public function test_preferences_persist_after_update()
    {
        $user = User::factory()->create();

        $this->service->setChannelPreference($user, 'email', false);
        $this->service->setTypePreference($user, 'inactivity', false);

        $freshUser = User::find($user->id);
        $preferences = $this->service->getPreferences($freshUser);

        $this->assertFalse($preferences['channels']['email']);
        $this->assertFalse($preferences['types']['inactivity']);
    }
}
