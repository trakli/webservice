<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GroupTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function createGroup(): TestResponse
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/groups', [
            'name' => 'My Group',
            'description' => 'test descriptoin',
        ]);

        return $response;
    }

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
            ],
            'message',
        ]);
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

        $this->assertDatabaseMissing('groups', ['id' => $id]);
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
}
