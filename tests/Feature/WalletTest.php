<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    public function test_api_user_can_create_wallets()
    {
        $response = $this->createWallet('bank');

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

    private function createWallet($type): TestResponse
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/wallets', [
            'name' => 'My Wallet',
            'type' => $type,
            'description' => 'test descriptoin',
        ]);

        return $response;
    }

    public function test_api_user_can_get_their_wallets()
    {
        $response = $this->createWallet('bank');

        $response->assertStatus(201);

        $response = $this->actingAs($this->user)->getJson('/api/v1/wallets');
        $response->assertStatus(200);
    }

    public function test_api_user_can_create_a_wallet_with_invalid_type()
    {
        $response = $this->createWallet('invalid type');

        $response->assertStatus(422);
    }

    public function test_api_user_can_update_their_wallets()
    {
        $response = $this->createWallet('bank');
        $response->assertStatus(201);

        $wallet = $response->json('data');
        $id = $wallet['id'];

        $response = $this->actingAs($this->user)->putJson('/api/v1/wallets/'.$id, [
            'name' => 'new name',
            'description' => 'new description',
        ]);

        $data = $response->json('data');
        $this->assertEquals('new name', $data['name']);
        $this->assertEquals('new description', $data['description']);
    }

    public function test_api_user_can_delete_their_wallets()
    {
        $response = $this->createWallet('bank');
        $response->assertStatus(201);

        $wallet = $response->json('data');
        $id = $wallet['id'];

        $response = $this->actingAs($this->user)->deleteJson('/api/v1/wallets/'.$id);
        $response->assertStatus(200);

        $this->assertDatabaseMissing('wallets', ['id' => $id]);
    }

    public function test_api_user_cannot_delete_another_users_wallet()
    {
        $response = $this->createWallet('bank');
        $response->assertStatus(201);

        $wallet = $response->json('data');
        $id = $wallet['id'];

        $user = User::factory()->create();
        $response = $this->actingAs($user)->deleteJson('/api/v1/wallets/'.$id);
        $response->assertStatus(404);
    }

    public function test_api_user_cannot_update_another_users_wallet()
    {
        $response = $this->createWallet('bank');
        $response->assertStatus(201);

        $wallet = $response->json('data');
        $id = $wallet['id'];

        $user = User::factory()->create();
        $response = $this->actingAs($user)->putJson('/api/v1/wallets/'.$id, [
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
