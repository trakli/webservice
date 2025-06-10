<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
                'type',
                'description',
                'user_id',
                'client_generated_id',
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

    public function test_api_user_can_create_wallets_with_client_id()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/wallets', [
            'name' => 'My Wallet (with client id)',
            'type' => 'bank',
            'currency' => 'XAF',
            'balance' => 0,
            'description' => 'test descriptoin',
            'client_id' => '123e4567-e89b-12d3-a456-426614174000',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('wallets', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_create_wallets_with_an_emoji()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/wallets', [
            'name' => 'My Wallet (with client id)',
            'type' => 'bank',
            'currency' => 'XAF',
            'balance' => 0,
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
        $this->assertDatabaseHas('wallets', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_update_a_wallet_with_an_emoji()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/wallets', [
            'name' => 'My Wallet (with client id)',
            'type' => 'bank',
            'currency' => 'XAF',
            'balance' => 0,
            'description' => 'test descriptoin',
            'icon' => '👆',
            'icon_type' => 'emoji',
        ]);

        $response->assertStatus(201);
        $id = $response->json('data.id');

        $response = $this->actingAs($this->user)->putJson('/api/v1/wallets/'.$id, [
            'name' => 'My Wallet (with client id)',
            'type' => 'bank',
            'currency' => 'XAF',
            'balance' => 0,
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

        $this->assertDatabaseHas('wallets', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_create_wallets_with_an_image()
    {
        $imageFile = UploadedFile::fake()->createWithContent(
            'image.png',
            'data:image/png;base64,someEncodedImagePNGImageHereYII='
        );

        $response = $this->actingAs($this->user)->postJson('/api/v1/wallets', [
            'name' => 'My Wallet (with client id)',
            'type' => 'bank',
            'currency' => 'XAF',
            'balance' => 0,
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

        $this->assertDatabaseHas('wallets', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_update_a_wallet_with_an_image()
    {
        $imageFile = UploadedFile::fake()->createWithContent(
            'image.png',
            'data:image/png;base64,someEncodedImagePNGImageHereYII='
        );

        $response = $this->actingAs($this->user)->postJson('/api/v1/wallets', [
            'name' => 'My Wallet (with client id)',
            'type' => 'bank',
            'currency' => 'XAF',
            'balance' => 0,
            'description' => 'test descriptoin',
            'icon' => '👆',
            'icon_type' => 'emoji',
        ]);

        $response->assertStatus(201);
        $id = $response->json('data.id');

        $response = $this->actingAs($this->user)->putJson('/api/v1/wallets/'.$id, [
            'name' => 'My Wallet (with client id)',
            'type' => 'bank',
            'currency' => 'XAF',
            'balance' => 0,
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

        $this->assertDatabaseHas('wallets', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_not_create_a_wallet_with_a_non_image_file()
    {
        $csvFile = UploadedFile::fake()->createWithContent(
            'test.csv',
            $content ?? "amount,currency,type,party,wallet,category,description,date\n".
        "100,USD,expense,John Doe,Wallet1,Food,Lunch,2023-01-01\n".
        '200,USD,income,Jane Doe,Wallet2,Salary,Monthly Salary,2023-01-02'
        );

        $response = $this->actingAs($this->user)->postJson('/api/v1/wallets', [
            'name' => 'My Wallet',
            'type' => 'bank',
            'currency' => 'USD',
            'balance' => 0,
            'description' => 'test descriptoin',
            'icon' => $csvFile,
            'icon_type' => 'image',
        ]);
        $response->assertStatus(422);

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
        $this->createWallet('bank');
        $this->createWallet('cash');
        $this->createWallet('credit_card');

        $response = $this->actingAs($this->user)->getJson('/api/v1/wallets');
        $response->assertStatus(200);

        $wallets = $response->json('data.data');
        $this->assertCount(3, $wallets);

        $types = array_column($wallets, 'type');
        $this->assertContains('bank', $types);
        $this->assertContains('cash', $types);
        $this->assertContains('credit_card', $types);
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

        // Update wallet including changing the type
        $response = $this->actingAs($this->user)->putJson('/api/v1/wallets/'.$id, [
            'name' => 'new name',
            'type' => 'credit_card',
            'description' => 'new description',
            'currency' => 'XAF',
        ]);

        $data = $response->json('data');
        $this->assertEquals('new name', $data['name']);
        $this->assertEquals('credit_card', $data['type']);
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

    public function test_wallet_balance_has_consistent_type()
    {
        $this->actingAs($this->user);

        $testWallet = $this->user->wallets()->create([
            'name' => 'Test Balance Type Wallet',
            'type' => 'bank',
            'balance' => 100.50,
            'currency' => 'XAF',
            'description' => 'Test wallet for balance type checking',
        ]);

        try {
            $response = $this->getJson('/api/v1/wallets');
            $response->assertStatus(200);

            $walletInList = collect($response->json('data.data'))
                ->firstWhere('id', $testWallet->id);

            $this->assertNotNull($walletInList, 'Test wallet should be in the list');
            $this->assertIsNumeric($walletInList['balance'] ?? 0, 'Balance in list should be numeric');

            $response = $this->getJson("/api/v1/wallets/{$testWallet->id}");
            $response->assertStatus(200);
            $this->assertIsNumeric($response->json('data.balance') ?? 0, 'Balance in show should be numeric');

            $response = $this->putJson("/api/v1/wallets/{$testWallet->id}", [
                'name' => 'Updated Wallet',
                'type' => 'bank',
                'balance' => 200.75,
                'currency' => 'XAF',
            ]);
            $response->assertStatus(200);
            $this->assertIsNumeric($response->json('data.balance') ?? 0, 'Updated balance should be numeric');
        } finally {
            $testWallet->delete();
        }
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
