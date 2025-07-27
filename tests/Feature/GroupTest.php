<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GroupTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    public function test_api_user_can_create_groups()
    {
        $response = $this->createGroup();

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'name',
                'description',
                'user_id',
                'slug',
                'client_generated_id',
            ],
            'message',
        ]);
    }

    private function createGroup(): TestResponse
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/groups', [
            'name' => 'My Group',
            'description' => 'test descriptoin',
        ]);

        return $response;
    }

    public function test_api_user_can_create_groups_with_client_id()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/groups', [
            'name' => 'My Group (with client id)',
            'description' => 'test descriptoin',
            'client_id' => '245cb3df-df3a-428b-a908-e5f74b8d58a4:245cb3df-df3a-428b-a908-e5f74b8d58a4',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('groups', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_create_groups_with_an_emoji()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/groups', [
            'name' => 'My Group (with client id)',
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
        $this->assertDatabaseHas('groups', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_update_a_group_emoji()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/groups', [
            'name' => 'My Group (with client id)',
            'description' => 'test descriptoin',
            'icon' => '👆',
            'icon_type' => 'emoji',
        ]);
        $response->assertStatus(201);
        $id = $response->json('data.id');

        $response = $this->actingAs($this->user)->putJson('/api/v1/groups/'.$id, [
            'name' => 'My Group (with client id)',
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
        $this->assertDatabaseHas('groups', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_create_groups_with_an_image()
    {
        $imageFile = UploadedFile::fake()->createWithContent(
            'image.png',
            'data:image/png;base64,someEncodedImagePNGImageHereYII='
        );

        $response = $this->actingAs($this->user)->postJson('/api/v1/groups', [
            'name' => 'My Group (with client id)',
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

        $this->assertDatabaseHas('groups', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_update_a_group_image()
    {
        $imageFile = UploadedFile::fake()->createWithContent(
            'image.png',
            'data:image/png;base64,someEncodedImagePNGImageHereYII='
        );
        $response = $this->actingAs($this->user)->postJson('/api/v1/groups', [
            'name' => 'My Group (with client id)',
            'description' => 'test descriptoin',
            'icon' => '👆',
            'icon_type' => 'emoji',
        ]);
        $response->assertStatus(201);
        $id = $response->json('data.id');

        $response = $this->actingAs($this->user)->putJson('/api/v1/groups/'.$id, [
            'name' => 'My Group (with client id)',
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

        $this->assertDatabaseHas('groups', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_not_create_a_group_with_a_non_image_file()
    {
        $csvFile = UploadedFile::fake()->createWithContent(
            'test.csv',
            $content ?? "amount,currency,type,party,wallet,category,description,date\n".
        "100,USD,expense,John Doe,Wallet1,Food,Lunch,2023-01-01\n".
        '200,USD,income,Jane Doe,Wallet2,Salary,Monthly Salary,2023-01-02'
        );

        $response = $this->actingAs($this->user)->postJson('/api/v1/groups', [
            'name' => 'My Group (with client id)',
            'description' => 'test descriptoin',
            'icon' => $csvFile,
            'icon_type' => 'image',
        ]);
        $response->assertStatus(422);

    }

    public function test_api_user_can_update_their_groups()
    {
        $response = $this->createGroup();
        $response->assertStatus(201);

        $group = $response->json('data');
        $id = $group['id'];

        $response = $this->actingAs($this->user)->putJson('/api/v1/groups/'.$id, [
            'name' => 'new name',
            'description' => 'new description',
        ]);

        $data = $response->json('data');
        $this->assertEquals('new name', $data['name']);
        $this->assertEquals('new description', $data['description']);
    }

    public function test_api_user_can_delete_their_groups()
    {
        $response = $this->createGroup();
        $response->assertStatus(201);

        $group = $response->json('data');
        $id = $group['id'];

        $response = $this->actingAs($this->user)->deleteJson('/api/v1/groups/'.$id);
        $response->assertStatus(204);

        $group = Group::find($id);
        $this->assertNull($group);
    }

    public function test_api_user_cannot_delete_another_users_group()
    {
        $response = $this->createGroup();
        $response->assertStatus(201);

        $group = $response->json('data');
        $id = $group['id'];

        $user = User::factory()->create();
        $response = $this->actingAs($user)->deleteJson('/api/v1/groups/'.$id);
        $response->assertStatus(404);
    }

    public function test_api_user_cannot_update_another_users_group()
    {
        $response = $this->createGroup();
        $response->assertStatus(201);

        $group = $response->json('data');
        $id = $group['id'];

        $user = User::factory()->create();
        $response = $this->actingAs($user)->putJson('/api/v1/groups/'.$id, [
            'name' => 'new name',
            'description' => 'new description',
        ]);

        $response->assertStatus(404);
    }

    public function test_api_user_can_update_their_groups_with_client_id()
    {
        $response = $this->createGroup();
        $response->assertStatus(201);

        $group = $response->json('data');
        $id = $group['id'];
        $deviceToken = '245cb3df-df3a-428b-a908-e5f74b8d58a4';
        $clientId = '245cb3df-df3a-428b-a908-e5f74b8d58a3';

        $response = $this->actingAs($this->user)->putJson('/api/v1/groups/'.$id, [
            'name' => 'new name',
            'description' => 'new description',
            'client_id' => "$deviceToken:$clientId",
        ]);

        $data = $response->json('data');
        $this->assertEquals('new name', $data['name']);
        $this->assertEquals('new description', $data['description']);
        $group = Group::find($id);
        $this->assertEquals($group->syncState->client_generated_id, $clientId);
    }

    public function test_api_user_cannot_create_group_with_invalid_client_id_format()
    {
        // Test with client_id that has no colon
        $response = $this->actingAs($this->user)->postJson('/api/v1/groups', [
            'name' => 'My Group (with invalid client id)',
            'description' => 'test description',
            'client_id' => '245cb3df-df3a-428b-a908-e5f74b8d58a4',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('must be in the format', $response->json('errors.0'));

        // Test with client_id that has invalid UUID
        $response = $this->actingAs($this->user)->postJson('/api/v1/groups', [
            'name' => 'My Group (with invalid UUID)',
            'description' => 'test description',
            'client_id' => 'invalid-uuid:245cb3df-df3a-428b-a908-e5f74b8d58a4',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('not a valid UUID', $response->json('errors.0'));

        // Test with client_id that has more than one colon
        $response = $this->actingAs($this->user)->postJson('/api/v1/groups', [
            'name' => 'My Group (with too many colons)',
            'description' => 'test description',
            'client_id' => '245cb3df-df3a-428b-a908-e5f74b8d58a4:245cb3df-df3a-428b-a908-e5f74b8d58a4:extra',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('must be in the format', $response->json('errors.0'));
    }

    public function test_api_user_device_creation_with_client_id()
    {
        $deviceToken = '245cb3df-df3a-428b-a908-e5f74b8d58a4';
        $clientId = '245cb3df-df3a-428b-a908-e5f74b8d58a3';

        // Create first group with client_id
        $response = $this->actingAs($this->user)->postJson('/api/v1/groups', [
            'name' => 'First Group with client id',
            'description' => 'test description',
            'client_id' => "$deviceToken:$clientId",
        ]);

        $response->assertStatus(201);
        $firstGroup = Group::find($response->json('data.id'));

        // Verify device was created
        $this->assertDatabaseHas('devices', ['deviceable_id' => $this->user->id, 'token' => $deviceToken, 'deviceable_type' => 'App\Models\User']);
        $device = $this->user->devices()->where('token', $deviceToken)->first();
        $this->assertNotNull($device);

        // Verify sync state has correct device_id and client_generated_id
        $this->assertEquals($clientId, $firstGroup->syncState->client_generated_id);
        $this->assertEquals($device->id, $firstGroup->syncState->device_id);

        // Create second group with same device_id but different client_id
        $secondClientId = '245cb3df-df3a-428b-a908-e5f74b8d58a5';
        $response = $this->actingAs($this->user)->postJson('/api/v1/groups', [
            'name' => 'Second Group with same device',
            'description' => 'test description',
            'client_id' => "$deviceToken:$secondClientId",
        ]);

        $response->assertStatus(201);
        $secondGroup = Group::find($response->json('data.id'));

        // Verify same device was used
        $this->assertEquals(1, $this->user->devices()->where('token', $deviceToken)->count());

        // Verify second group has correct client_generated_id but same device_id
        $this->assertEquals($secondClientId, $secondGroup->syncState->client_generated_id);
        $this->assertEquals($device->id, $secondGroup->syncState->device_id);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }
}
