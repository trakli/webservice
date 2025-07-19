<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PartyTest extends TestCase
{
    use RefreshDatabase;

    private $wallet;

    private $party;

    private $user;

    public function test_api_user_can_create_parties()
    {
        $response = $this->createParty();

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'name',
                'description',
                'user_id',
                'type',
                'client_generated_id',
            ],
            'message',
        ]);
        $this->assertEquals('individual', $response->json('data.type'));
    }

    private function createParty(): TestResponse
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/parties', [
            'name' => 'My Party',
            'description' => 'test descriptoin',
            'type' => 'individual',
        ]);

        return $response;
    }

    public function test_api_user_can_create_parties_with_client_id()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/parties', [
            'name' => 'My Party (with client id)',
            'description' => 'test descriptoin',
            'client_id' => '245cb3df-df3a-428b-a908-e5f74b8d58a4:245cb3df-df3a-428b-a908-e5f74b8d58a4',
        ]);
        $response->assertStatus(201);
        $this->assertDatabaseHas('parties', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_create_a_party_with_an_emoji()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/parties', [
            'name' => 'My Party (with client id)',
            'description' => 'test descriptoin',
            'icon' => '👆',
            'icon_type' => 'emoji',
        ]);
        $response->assertStatus(201)->assertJsonStructure([
            'success',
            'data' => [
                'icon' => [
                    'type',
                    'content',
                ],
            ],
        ]);
        $this->assertEquals('👆', $response->json('data.icon.content'));
        $this->assertEquals('emoji', $response->json('data.icon.type'));
        $this->assertDatabaseHas('parties', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_update_a_party_emoji()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/parties', [
            'name' => 'My Party (with client id)',
            'description' => 'test descriptoin',
            'icon' => '👆',
            'icon_type' => 'emoji',
        ]);
        $response->assertStatus(201);
        $id = $response->json('data.id');

        $response = $this->actingAs($this->user)->putJson('/api/v1/parties/'.$id, [
            'name' => 'My Party (with client id)',
            'description' => 'test descriptoin',
            'icon' => '✅',
            'icon_type' => 'emoji',
        ]);

        $response->assertStatus(200)->assertJsonStructure([
            'success',
            'data' => [
                'icon' => [
                    'type',
                    'content',
                ],
            ],
        ]);
        $this->assertEquals('✅', $response->json('data.icon.content'));
        $this->assertEquals('emoji', $response->json('data.icon.type'));

        $this->assertDatabaseHas('parties', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_create_a_party_with_an_image()
    {
        $imageFile = UploadedFile::fake()->createWithContent(
            'image.png',
            'data:image/png;base64,someEncodedImagePNGImageHereYII='
        );

        $response = $this->actingAs($this->user)->postJson('/api/v1/parties', [
            'name' => 'My Party (with client id)',
            'description' => 'test descriptoin',
            'icon' => $imageFile,
            'icon_type' => 'image',
        ]);
        $response->assertStatus(201)->assertJsonStructure([
            'success',
            'data' => [
                'icon' => [
                    'type',
                    'content',
                ],
            ],
        ]);
        $this->assertEquals('image', $response->json('data.icon.type'));

        $this->assertDatabaseHas('parties', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_update_a_party_image()
    {
        $imageFile = UploadedFile::fake()->createWithContent(
            'image.png',
            'data:image/png;base64,someEncodedImagePNGImageHereYII='
        );
        $response = $this->actingAs($this->user)->postJson('/api/v1/parties', [
            'name' => 'My Party (with client id)',
            'description' => 'test descriptoin',
            'icon' => '👆',
            'icon_type' => 'emoji',
        ]);
        $response->assertStatus(201);
        $id = $response->json('data.id');

        $response = $this->actingAs($this->user)->putJson('/api/v1/parties/'.$id, [
            'name' => 'My Party (with client id)',
            'description' => 'test descriptoin',
            'icon' => $imageFile,
            'icon_type' => 'image',
        ]);

        $response->assertStatus(200)->assertJsonStructure([
            'success',
            'data' => [
                'icon' => [
                    'type',
                    'content',
                ],
            ],
        ]);

        $this->assertNotNull($response->json('data.icon.image'));
        $this->assertEquals('png', $response->json('data.icon.image.type'));

        $this->assertDatabaseHas('parties', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_not_create_a_party_with_a_non_image_file()
    {
        $csvFile = UploadedFile::fake()->createWithContent(
            'test.csv',
            $content ?? "amount,currency,type,party,wallet,category,description,date\n".
        "100,USD,expense,John Doe,Wallet1,Food,Lunch,2023-01-01\n".
        '200,USD,income,Jane Doe,Wallet2,Salary,Monthly Salary,2023-01-02'
        );

        $response = $this->actingAs($this->user)->postJson('/api/v1/parties', [
            'name' => 'My Party (with client id)',
            'description' => 'test descriptoin',
            'icon' => $csvFile,
            'icon_type' => 'image',
        ]);
        $response->assertStatus(422);

    }

    public function test_api_user_can_not_create_two_parties_with_the_same_name()
    {
        $response = $this->createParty();

        $response->assertStatus(201);

        $response = $this->createParty();

        $response->assertStatus(400);
    }

    public function test_api_user_can_update_their_parties()
    {
        $response = $this->createParty();
        $response->assertStatus(201);

        $party = $response->json('data');
        $id = $party['id'];

        $response = $this->actingAs($this->user)->putJson('/api/v1/parties/'.$id, [
            'name' => 'new name',
            'description' => 'new description',
        ]);

        $data = $response->json('data');
        $this->assertEquals('new name', $data['name']);
        $this->assertEquals('new description', $data['description']);
    }

    public function test_api_user_can_delete_their_parties()
    {
        $response = $this->createParty();
        $response->assertStatus(201);

        $party = $response->json('data');
        $id = $party['id'];

        $response = $this->actingAs($this->user)->deleteJson('/api/v1/parties/'.$id);
        $response->assertStatus(200);

        $party = Party::find($id);
        $this->assertNull($party);
    }

    public function test_api_user_cannot_delete_another_users_party()
    {
        $response = $this->createParty();
        $response->assertStatus(201);

        $party = $response->json('data');
        $id = $party['id'];

        $user = User::factory()->create();
        $response = $this->actingAs($user)->deleteJson('/api/v1/parties/'.$id);
        $response->assertStatus(404);
    }

    public function test_api_user_cannot_update_another_users_party()
    {
        $response = $this->createParty();
        $response->assertStatus(201);

        $party = $response->json('data');
        $id = $party['id'];

        $user = User::factory()->create();
        $response = $this->actingAs($user)->putJson('/api/v1/parties/'.$id, [
            'name' => 'new name',
            'description' => 'new description',
        ]);

        $response->assertStatus(404);
    }

    public function test_api_user_can_update_their_parties_with_client_id()
    {
        $response = $this->createParty();
        $response->assertStatus(201);

        $party = $response->json('data');
        $id = $party['id'];
        $deviceToken = '245cb3df-df3a-428b-a908-e5f74b8d58a4';
        $clientId = '245cb3df-df3a-428b-a908-e5f74b8d58a3';

        $response = $this->actingAs($this->user)->putJson('/api/v1/parties/'.$id, [
            'name' => 'new name',
            'description' => 'new description',
            'client_id' => "$deviceToken:$clientId",
        ]);

        $data = $response->json('data');
        $this->assertEquals('new name', $data['name']);
        $this->assertEquals('new description', $data['description']);
        $party = Party::find($id);
        $this->assertEquals($party->syncState->client_generated_id, $clientId);
    }

    public function test_api_user_cannot_create_party_with_invalid_client_id_format()
    {
        // Test with client_id that has no colon
        $response = $this->actingAs($this->user)->postJson('/api/v1/parties', [
            'name' => 'My Party (with invalid client id)',
            'description' => 'test description',
            'client_id' => '245cb3df-df3a-428b-a908-e5f74b8d58a4',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('must be in the format', $response->json('errors.client_id.0'));

        // Test with client_id that has invalid UUID
        $response = $this->actingAs($this->user)->postJson('/api/v1/parties', [
            'name' => 'My Party (with invalid UUID)',
            'description' => 'test description',
            'client_id' => 'invalid-uuid:245cb3df-df3a-428b-a908-e5f74b8d58a4',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('not a valid UUID', $response->json('errors.client_id.0'));

        // Test with client_id that has more than one colon
        $response = $this->actingAs($this->user)->postJson('/api/v1/parties', [
            'name' => 'My Party (with too many colons)',
            'description' => 'test description',
            'client_id' => '245cb3df-df3a-428b-a908-e5f74b8d58a4:245cb3df-df3a-428b-a908-e5f74b8d58a4:extra',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('must be in the format', $response->json('errors.client_id.0'));
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
