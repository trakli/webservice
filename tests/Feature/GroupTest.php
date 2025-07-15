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
            'client_id' => '123e4567-e89b-12d3-a456-426614174000',
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }
}
