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

    private function createWallet($type, $opening_balance = 0, $currency = 'XAF'): TestResponse
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/wallets', [
            'name' => 'My Wallet',
            'type' => $type,
            'description' => 'test description',
            'currency' => $currency,
            'balance' => $opening_balance,
        ]);

        return $response;
    }

    public function test_api_user_cannot_create_wallet_with_invalid_currency()
    {
        $response = $this->createWallet('bank', 1000, 'INVALID');
        $response->assertStatus(422);
    }

    public function test_api_user_can_create_wallet_with_decimal_balance()
    {
        $response = $this->createWallet('bank', 1000.50);
        $response->assertStatus(201);
        $this->assertEquals(1000.50, $response->json()['data']['balance']);
    }

    public function test_api_user_cannot_create_wallet_with_too_many_decimal_places()
    {
        $response = $this->createWallet('bank', 1000.123456789);
        $response->assertStatus(422);
    }

    public function test_api_user_can_create_wallet_with_an_opening_balance()
    {
        $response = $this->createWallet('bank', 1000);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'name',
                'description',
                'user_id',
                'balance',
                'currency',
            ],
            'message',
        ]);
        $this->assertEquals(1000, $response->json()['data']['balance']);
    }

    public function test_api_user_can_create_wallet_with_a_different_currency()
    {
        $response = $this->createWallet('bank', 1000, 'USD');

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'name',
                'description',
                'user_id',
                'balance',
                'currency',
            ],
            'message',
        ]);
        $this->assertEquals('USD', $response->json()['data']['currency']);
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
            'currency' => 'XAF',
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
            'currency' => 'USD',
        ]);

        $response->assertStatus(404);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }
}
