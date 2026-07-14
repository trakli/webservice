<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InactivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    public function test_api_user_can_create_config_with_allowed_key()
    {

        $response = $this->actingAs($this->user)->postJson('/api/v1/configurations', [
            'key' => 'default-wallet',
            'type' => 'string',
            'value' => 'test description',
        ]);
        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Configuration added successfully',
        ]);
    }

    public function test_landing_experience_can_be_saved_and_updated()
    {
        $this->actingAs($this->user)->postJson('/api/v1/configurations', [
            'key' => 'landing-experience',
            'type' => 'string',
            'value' => 'dashboard',
        ])->assertStatus(201);

        $this->assertEquals(
            'dashboard',
            $this->user->fresh()->getConfigValue(\App\Support\ConfigurationKeys::LANDING_EXPERIENCE)
        );

        $this->actingAs($this->user)->putJson('/api/v1/configurations/landing-experience', [
            'type' => 'string',
            'value' => 'chat',
        ])->assertStatus(200);

        $this->assertEquals(
            'chat',
            $this->user->fresh()->getConfigValue(\App\Support\ConfigurationKeys::LANDING_EXPERIENCE)
        );
    }

    public function test_landing_experience_rejects_invalid_value()
    {
        $this->actingAs($this->user)->postJson('/api/v1/configurations', [
            'key' => 'landing-experience',
            'type' => 'string',
            'value' => 'not-a-mode',
        ])->assertStatus(422);
    }

    public function test_api_user_can_not_create_config_with_unallowed_key()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/configurations', [
            'key' => 'default-config',
            'type' => 'string',
            'value' => 'test description',
        ]);
        $response->assertStatus(422);
    }

    public function test_api_user_can_update_config_with_allowed_key()
    {
        $key = 'default-wallet';
        $this->actingAs($this->user)->postJson('/api/v1/configurations', [
            'key' => $key,
            'type' => 'string',
            'value' => 'initial value',
        ])->assertStatus(201);
        $response = $this->actingAs($this->user)->putJson("/api/v1/configurations/{$key}", [
            'type' => 'string',
            'value' => 'updated value',
        ]);
        $response->assertStatus(200);
        $response->assertJsonFragment(['value' => 'updated value']);
    }

    public function test_api_user_can_not_update_config_with_unallowed_key()
    {
        $key = 'unallowed-key';
        $response = $this->actingAs($this->user)->putJson("/api/v1/configurations/{$key}", [
            'type' => 'string',
            'value' => 'some value',
        ]);
        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Invalid configuration key.']);
    }

    public function test_api_user_can_store_and_retrieve_string_config()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/configurations', [
            'key' => 'default-wallet',
            'type' => 'string',
            'value' => '00000000-0000-0000-0000-000000000000:12345678-1234-1234-1234-123456789012',
        ]);
        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Configuration added successfully',
        ]);
    }

    public function test_api_user_can_update_config_with_bool_type()
    {
        $key = 'onboarding-complete';
        $this->actingAs($this->user)->postJson('/api/v1/configurations', [
            'key' => $key,
            'type' => 'bool',
            'value' => false,
        ])->assertStatus(201);
        $response = $this->actingAs($this->user)->putJson("/api/v1/configurations/{$key}", [
            'type' => 'bool',
            'value' => true,
        ]);
        $response->assertStatus(200);
        $response->assertJsonFragment(['value' => true]);
    }

    public function test_api_user_can_create_and_update_last_inactivity_reminder_sent()
    {
        $key = InactivityService::CONFIG_LAST_REMINDER_SENT;
        $this->actingAs($this->user)->postJson('/api/v1/configurations', [
            'key' => $key,
            'type' => 'date',
            'value' => now()->subDays(3)->toIso8601String(),
        ])->assertStatus(201);

        $response = $this->actingAs($this->user)->putJson("/api/v1/configurations/{$key}", [
            'type' => 'date',
            'value' => now()->toIso8601String(),
        ]);
        $response->assertStatus(200);
    }

    public function test_api_user_can_create_and_update_inactivity_reminder_count()
    {
        $key = InactivityService::CONFIG_INACTIVITY_REMINDER_COUNT;
        $this->actingAs($this->user)->postJson('/api/v1/configurations', [
            'key' => $key,
            'type' => 'int',
            'value' => 0,
        ])->assertStatus(201);

        $response = $this->actingAs($this->user)->putJson("/api/v1/configurations/{$key}", [
            'type' => 'int',
            'value' => 2,
        ]);
        $response->assertStatus(200);
        $response->assertJsonFragment(['value' => 2]);
    }

    public function test_api_user_can_create_and_update_inactivity_reminders_enabled()
    {
        $key = InactivityService::CONFIG_INACTIVITY_REMINDERS_ENABLED;
        $this->actingAs($this->user)->postJson('/api/v1/configurations', [
            'key' => $key,
            'type' => 'bool',
            'value' => true,
        ])->assertStatus(201);

        $response = $this->actingAs($this->user)->putJson("/api/v1/configurations/{$key}", [
            'type' => 'bool',
            'value' => false,
        ]);
        $response->assertStatus(200);
        $response->assertJsonFragment(['value' => false]);
    }

    public function test_inactivity_service_config_keys_are_in_allowlist()
    {
        $allowed = array_keys(config('model-configuration.allowed_keys'));

        $this->assertContains(InactivityService::CONFIG_LAST_REMINDER_SENT, $allowed);
        $this->assertContains(InactivityService::CONFIG_INACTIVITY_REMINDER_COUNT, $allowed);
        $this->assertContains(InactivityService::CONFIG_INACTIVITY_REMINDERS_ENABLED, $allowed);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }
}
