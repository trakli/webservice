<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    public function test_api_user_device_can_be_reassigned_to_another_user()
    {
        $deviceToken = '245cb3df-df3a-428b-a908-e5f74b8d58a4';
        $clientId = '245cb3df-df3a-428b-a908-e5f74b8d58a3';

        // Create first party with client_id
        $response = $this->actingAs($this->user)->postJson('/api/v1/parties', [
            'name' => 'First Party with client id',
            'description' => 'test description',
            'client_id' => "$deviceToken:$clientId",
        ]);

        $response->assertStatus(201);
        $firstParty = Party::find($response->json('data.id'));

        // Verify device was created
        $this->assertDatabaseHas('devices', ['deviceable_id' => $this->user->id, 'token' => $deviceToken, 'deviceable_type' => 'App\Models\User']);
        $device = $this->user->devices()->where('token', $deviceToken)->first();
        $this->assertNotNull($device);

        // Verify sync state has correct device_id and client_generated_id
        $this->assertEquals($clientId, $firstParty->syncState->client_generated_id);
        $this->assertEquals($device->id, $firstParty->syncState->device_id);

        $new_user = User::factory()->create();
        // Create first party with client_id
        $response = $this->actingAs($new_user)->postJson('/api/v1/parties', [
            'name' => 'First Party with client id from new user',
            'description' => 'test description',
            'client_id' => "$deviceToken:$clientId",
        ]);
        $secondParty = Party::find($response->json('data.id'));

        // Verify device was reassigned
        $this->assertDatabaseHas('devices', ['deviceable_id' => $new_user->id, 'token' => $deviceToken, 'deviceable_type' => 'App\Models\User']);
        $device = $new_user->devices()->where('token', $deviceToken)->first();
        $this->assertNotNull($device);
        $this->assertNull($this->user->devices()->where('token', $deviceToken)->first());

        $this->assertEquals($device->id, $secondParty->syncState->device_id);

    }

    public function test_api_user_device_creation_with_client_id()
    {
        $deviceToken = '245cb3df-df3a-428b-a908-e5f74b8d58a4';
        $clientId = '245cb3df-df3a-428b-a908-e5f74b8d58a3';

        // Create first party with client_id
        $response = $this->actingAs($this->user)->postJson('/api/v1/parties', [
            'name' => 'First Party with client id',
            'description' => 'test description',
            'client_id' => "$deviceToken:$clientId",
        ]);

        $response->assertStatus(201);
        $firstParty = Party::find($response->json('data.id'));

        // Verify device was created
        $this->assertDatabaseHas('devices', ['deviceable_id' => $this->user->id, 'token' => $deviceToken, 'deviceable_type' => 'App\Models\User']);
        $device = $this->user->devices()->where('token', $deviceToken)->first();
        $this->assertNotNull($device);

        // Verify sync state has correct device_id and client_generated_id
        $this->assertEquals($clientId, $firstParty->syncState->client_generated_id);
        $this->assertEquals($device->id, $firstParty->syncState->device_id);

        // Create second party with same device_id but different client_id
        $secondClientId = '245cb3df-df3a-428b-a908-e5f74b8d58a5';
        $response = $this->actingAs($this->user)->postJson('/api/v1/parties', [
            'name' => 'Second Party with same device',
            'description' => 'test description',
            'client_id' => "$deviceToken:$secondClientId",
        ]);

        $response->assertStatus(201);
        $secondParty = Party::find($response->json('data.id'));

        // Verify same device was used
        $this->assertEquals(1, $this->user->devices()->where('token', $deviceToken)->count());

        // Verify second party has correct client_generated_id but same device_id
        $this->assertEquals($secondClientId, $secondParty->syncState->client_generated_id);
        $this->assertEquals($device->id, $secondParty->syncState->device_id);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }
}
