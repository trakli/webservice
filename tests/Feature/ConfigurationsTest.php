<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigurationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_user_can_get_all_configurations()
    {
        $user = User::factory()->create();

        // Create some test configurations for the user
        $user->configurations()->create([
            'key' => 'theme_preference',
            'type' => 'array',
            'value' => ['theme' => 'dark', 'color' => '#333333'],
        ]);

        $user->configurations()->create([
            'key' => 'notification_settings',
            'value' => ['email' => true, 'push' => false],
        ]);

        // Make the request to get all configurations
        $response = $this->actingAs($user)->getJson('/api/v1/configurations');

        // Assert the response is successful and has the correct structure
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'user_id',
                        'key',
                        'value',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);

        // Assert that the response contains the created configurations
        $this->assertCount(2, $response->json('data'));
    }

    public function test_api_user_can_add_configuration()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/configurations', [
            'key' => 'theme_preference',
            'type' => 'array',
            'value' => ['theme' => 'dark', 'color' => '#333333'],
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
            ]);

        // Assert the configuration was stored in the database
        $this->assertDatabaseHas('user_configurations', [
            'user_id' => $user->id,
            'key' => 'theme_preference',
        ]);
    }

    public function test_api_user_can_update_configuration()
    {
        $user = User::factory()->create();

        // Create a configuration to update
        $configuration = $user->configurations()->create([
            'key' => 'theme_preference',
            'type' => 'array',
            'value' => ['theme' => 'dark', 'color' => '#333333'],
        ]);

        // Update the configuration
        $response = $this->actingAs($user)->putJson('/api/v1/configurations/'.$configuration->key, [
            'value' => ['theme' => 'light', 'color' => '#ffffff'],
            'type' => 'array',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
            ]);

        // Assert the configuration was updated in the database
        $this->assertDatabaseHas('user_configurations', [
            'user_id' => $user->id,
            'key' => 'theme_preference',
        ]);

        // Refresh the configuration from the database
        $configuration->refresh();

        // Assert the value was updated
        $this->assertEquals('light', $configuration->value['theme']);
        $this->assertEquals('#ffffff', $configuration->value['color']);
    }

    public function test_api_user_can_delete_configuration()
    {
        $user = User::factory()->create();

        // Create a configuration to delete
        $configuration = $user->configurations()->create([
            'key' => 'theme_preference_color',
            'type' => 'string',
            'value' => '#333333',
        ]);

        // Delete the configuration
        $response = $this->actingAs($user)->deleteJson('/api/v1/configurations/'.$configuration->key);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
            ]);

        // Assert the configuration was deleted from the database
        $this->assertDatabaseMissing('user_configurations', [
            'id' => $configuration->id,
        ]);
    }

    public function test_api_user_cannot_update_nonexistent_configuration()
    {
        $user = User::factory()->create();

        // Try to update a configuration that doesn't exist
        $response = $this->actingAs($user)->putJson('/api/v1/configurations/999999', [
            'value' => 2,
            'type' => 'int',
        ]);

        $response->assertStatus(404);
    }

    public function test_api_user_cannot_delete_nonexistent_configuration()
    {
        $user = User::factory()->create();

        // Try to delete a configuration that doesn't exist
        $response = $this->actingAs($user)->deleteJson('/api/v1/configurations/999999');

        $response->assertStatus(404);
    }

    public function test_api_user_cannot_access_another_users_configurations()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // Create a configuration for user1
        $configuration = $user1->configurations()->create([
            'key' => 'theme_preference',
            'type' => 'float',
            'value' => 2.45,
        ]);

        // Try to access user1's configuration as user2
        $response = $this->actingAs($user2)->getJson('/api/v1/configurations');

        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));
    }

    public function test_unauthorized_user_cannot_access_configurations()
    {
        // Try to access configurations without authentication
        $response = $this->getJson('/api/v1/configurations');
        $response->assertStatus(401);
    }

    public function test_api_user_cannot_add_configuration_with_missing_key()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/configurations', [
            'value' => ['theme' => 'dark', 'color' => '#333333'],
            'type' => 'array',
        ]);

        $response->assertStatus(422);
    }

    public function test_api_user_cannot_add_configuration_with_missing_value()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/configurations', [
            'key' => 'theme_preference',
            'type' => 'string',
        ]);

        $response->assertStatus(422);
    }

    public function test_api_user_cannot_update_configuration_with_missing_value()
    {
        $user = User::factory()->create();

        // Create a configuration to update
        $configuration = $user->configurations()->create([
            'key' => 'theme_preference',
            'value' => ['theme' => 'dark', 'color' => '#333333'],
            'type' => 'array',
        ]);

        // Try to update without providing a value
        $response = $this->actingAs($user)->putJson('/api/v1/configurations/theme_preference', []);

        $response->assertStatus(422);
    }
}
