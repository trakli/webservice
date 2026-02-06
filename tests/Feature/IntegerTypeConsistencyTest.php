<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegerTypeConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    private $wallet;

    private $party;

    private $group;

    private $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->wallet = $this->user->wallets()->create([
            'name' => 'Test Wallet',
            'balance' => 1000,
        ]);

        $this->party = $this->user->parties()->create([
            'name' => 'Test Party',
            'type' => 'personal',
        ]);

        $this->group = $this->user->groups()->create([
            'name' => 'Test Group',
            'type' => 'personal',
        ]);

        $this->category = $this->user->categories()->create([
            'name' => 'Test Category',
            'type' => 'expense',
        ]);
    }

    public function test_transaction_create_returns_integer_ids(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'group_id' => $this->group->id,
            'categories' => [$this->category->id],
            'datetime' => '2025-04-30T15:17:54.120Z',
        ]);

        $response->assertStatus(201);
        $data = $response->json('data');

        $this->assertIsInt($data['id'], 'Transaction id should be integer');
        $this->assertIsInt($data['wallet_id'], 'wallet_id should be integer');
        $this->assertIsInt($data['party_id'], 'party_id should be integer');
        $this->assertIsInt($data['user_id'], 'user_id should be integer');

        $this->assertIsInt($data['wallet']['id'], 'Nested wallet.id should be integer');
        $this->assertIsInt($data['party']['id'], 'Nested party.id should be integer');
        $this->assertIsInt($data['group']['id'], 'Nested group.id should be integer');

        foreach ($data['categories'] as $category) {
            $this->assertIsInt($category['id'], 'Category id should be integer');
            $this->assertIsInt($category['user_id'], 'Category user_id should be integer');
        }
    }

    public function test_transaction_update_returns_integer_ids(): void
    {
        $createResponse = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
        ]);

        $createResponse->assertStatus(201);
        $transactionId = $createResponse->json('data.id');

        $updateResponse = $this->actingAs($this->user)->putJson("/api/v1/transactions/{$transactionId}", [
            'amount' => 200,
            'updated_at' => '2026-05-01T15:17:54.120Z',
        ]);

        $updateResponse->assertStatus(200);
        $data = $updateResponse->json('data');

        $this->assertIsInt($data['id'], 'Transaction id should be integer after update');
        $this->assertIsInt($data['wallet_id'], 'wallet_id should be integer after update');
        $this->assertIsInt($data['party_id'], 'party_id should be integer after update');
        $this->assertIsInt($data['user_id'], 'user_id should be integer after update');

        $this->assertIsInt($data['wallet']['id'], 'Nested wallet.id should be integer after update');
        $this->assertIsInt($data['party']['id'], 'Nested party.id should be integer after update');
    }

    public function test_transaction_update_with_wallet_change_returns_integer_ids(): void
    {
        $newWallet = $this->user->wallets()->create([
            'name' => 'New Wallet',
            'balance' => 500,
        ]);

        $createResponse = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
        ]);

        $createResponse->assertStatus(201);
        $transactionId = $createResponse->json('data.id');

        $updateResponse = $this->actingAs($this->user)->putJson("/api/v1/transactions/{$transactionId}", [
            'wallet_id' => $newWallet->id,
            'updated_at' => '2026-05-01T15:17:54.120Z',
        ]);

        $updateResponse->assertStatus(200);
        $data = $updateResponse->json('data');

        $this->assertIsInt($data['wallet_id'], 'wallet_id should be integer after wallet change');
        $this->assertEquals($newWallet->id, $data['wallet_id']);
        $this->assertIsInt($data['wallet']['id'], 'Nested wallet.id should be integer after wallet change');
        $this->assertEquals($newWallet->id, $data['wallet']['id']);
    }

    public function test_transaction_get_returns_integer_ids(): void
    {
        $createResponse = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
        ]);

        $createResponse->assertStatus(201);
        $transactionId = $createResponse->json('data.id');

        $getResponse = $this->actingAs($this->user)->getJson("/api/v1/transactions/{$transactionId}");

        $getResponse->assertStatus(200);
        $data = $getResponse->json('data');

        $this->assertIsInt($data['id'], 'Transaction id should be integer on GET');
        $this->assertIsInt($data['wallet_id'], 'wallet_id should be integer on GET');
        $this->assertIsInt($data['party_id'], 'party_id should be integer on GET');
        $this->assertIsInt($data['user_id'], 'user_id should be integer on GET');

        $this->assertIsInt($data['wallet']['id'], 'Nested wallet.id should be integer on GET');
        $this->assertIsInt($data['party']['id'], 'Nested party.id should be integer on GET');
    }

    public function test_transaction_list_returns_integer_ids(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/transactions?type=expense');

        $response->assertStatus(200);
        $transactions = $response->json('data.data');

        $this->assertNotEmpty($transactions);

        foreach ($transactions as $transaction) {
            $this->assertIsInt($transaction['id'], 'Transaction id should be integer in list');
            $this->assertIsInt($transaction['wallet_id'], 'wallet_id should be integer in list');
            $this->assertIsInt($transaction['user_id'], 'user_id should be integer in list');
            $this->assertIsInt($transaction['wallet']['id'], 'Nested wallet.id should be integer in list');
        }
    }

    public function test_wallet_returns_integer_id(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/wallets');

        $response->assertStatus(200);
        $wallets = $response->json('data.data');

        $this->assertNotEmpty($wallets);

        foreach ($wallets as $wallet) {
            $this->assertIsInt($wallet['id'], 'Wallet id should be integer');
        }
    }

    public function test_category_returns_integer_id(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/categories');

        $response->assertStatus(200);
        $categories = $response->json('data.data');

        $this->assertNotEmpty($categories);

        foreach ($categories as $category) {
            $this->assertIsInt($category['id'], 'Category id should be integer');
            $this->assertIsInt($category['user_id'], 'Category user_id should be integer');
        }
    }

    public function test_party_returns_integer_id(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/parties');

        $response->assertStatus(200);
        $parties = $response->json('data.data');

        $this->assertNotEmpty($parties);

        foreach ($parties as $party) {
            $this->assertIsInt($party['id'], 'Party id should be integer');
            $this->assertIsInt($party['user_id'], 'Party user_id should be integer');
        }
    }

    public function test_group_returns_integer_id(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/groups');

        $response->assertStatus(200);
        $groups = $response->json('data.data');

        $this->assertNotEmpty($groups);

        foreach ($groups as $group) {
            $this->assertIsInt($group['id'], 'Group id should be integer');
        }
    }
}
