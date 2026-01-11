<?php

namespace Tests\Feature;

use App\Models\User;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }
}
