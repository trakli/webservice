<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ],
            'message',
        ]);
    }

    private function createParty(): TestResponse
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/parties', [
            'name' => 'My Party',
            'description' => 'test descriptoin',
        ]);

        return $response;
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

        $this->assertDatabaseMissing('parties', ['id' => $id]);
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }
}
