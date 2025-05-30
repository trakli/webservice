<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionsTest extends TestCase
{
    use RefreshDatabase;

    private $wallet;

    private $party;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallet = User::factory()->create()->wallets()->create([
            'name' => 'Wallet',
            'balance' => 1000,
        ]);

        $this->party = User::factory()->create()->parties()->create([
            'name' => 'Party',
            'type' => 'personal',
        ]);
        $this->user = User::factory()->create();
    }

    private function createTransaction(string $type): array
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => $type,
            'amount' => 100,
            'description' => 'Test transaction description',
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'type',
                'amount',
                'description',
                'datetime',
                'party_id',
                'wallet_id',
                'user_id',
                'client_generated_id',
            ],
            'message',
        ]);

        return $response->json('data');
    }

    public function test_api_user_can_create_transactions()
    {
        $expense = $this->createTransaction('expense');
        $income = $this->createTransaction('income');

        $this->assertDatabaseHas('transactions', ['id' => $expense['id']]);
        $this->assertDatabaseHas('transactions', ['id' => $income['id']]);
    }

    public function test_transaction_response_includes_client_generated_ids()
    {
        $this->wallet->setClientGeneratedId('550e8400-e29b-41d4-a716-446655440000');
        $this->party->setClientGeneratedId('550e8400-e29b-41d4-a716-446655440001');

        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'description' => 'Test transaction with client IDs',
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
        ]);

        $response->assertStatus(201);
        $data = $response->json('data');

        $response->assertJsonStructure([
            'data' => [
                'wallet_client_generated_id',
                'party_client_generated_id',
                'wallet' => ['client_generated_id'],
                'party' => ['client_generated_id'],
            ],
        ]);

        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $data['wallet_client_generated_id']);
        $this->assertEquals('550e8400-e29b-41d4-a716-446655440001', $data['party_client_generated_id']);
        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $data['wallet']['client_generated_id']);
        $this->assertEquals('550e8400-e29b-41d4-a716-446655440001', $data['party']['client_generated_id']);
    }

    public function test_api_user_can_create_transactions_with_client_id()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
            'client_id' => '123e4567-e89b-12d3-a456-426614174000',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('transactions', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_get_their_transactions()
    {
        $this->createTransaction('expense');
        $this->createTransaction('income');

        $response = $this->actingAs($this->user)->getJson('/api/v1/transactions?type=expense');
        $response->assertStatus(200);

        $response = $this->actingAs($this->user)->getJson('/api/v1/transactions?type=income');
        $response->assertStatus(200);

        // Test limit parameter
        $this->createTransaction('expense');
        $this->createTransaction('expense');

        $response = $this->actingAs($this->user)->getJson('/api/v1/transactions?type=expense&limit=2');
        $response->assertStatus(200)
            ->assertJsonCount(2, 'data.data');
    }

    public function test_api_user_can_update_their_transactions()
    {
        $expense = $this->createTransaction('expense');

        $response = $this->actingAs($this->user)->putJson('/api/v1/transactions/'.$expense['id'], [
            'amount' => 200,
            'updated_at' => '2025-05-01T15:17:54.120Z',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'amount',
                    'wallet_id',
                    'party_id',
                    'datetime',
                ],
                'message',
            ]);
    }

    public function test_api_user_cannot_create_transaction_with_invalid_type()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'invalid_type',
            'amount' => 100,
            'wallet_id' => 1,
            'datetime' => '2025-04-30T15:17:54.120Z',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_api_user_cannot_create_transaction_with_invalid_amount()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => -100,
            'wallet_id' => 1,
            'datetime' => '2025-04-30T15:17:54.120Z',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_api_user_cannot_create_transaction_with_missing_required_fields()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'description' => 'Test transaction',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'amount']);
    }

    public function test_unauthorized_user_cannot_access_transactions()
    {
        $response = $this->getJson('/api/v1/transactions');
        $response->assertStatus(401);
    }

    public function test_api_user_can_delete_their_transaction()
    {
        $expense = $this->createTransaction('expense');

        $response = $this->actingAs($this->user)->deleteJson("/api/v1/transactions/{$expense['id']}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('transactions', ['id' => $expense['id']]);
    }

    public function test_api_user_cannot_delete_non_existent_transaction()
    {
        $response = $this->actingAs($this->user)->deleteJson('/api/v1/transactions/999');
        $response->assertStatus(404);
    }

    public function test_api_user_cannot_access_another_users_transaction()
    {
        $user2 = User::factory()->create();

        $expense = $this->createTransaction('expense');

        $this->actingAs($user2)
            ->getJson("/api/v1/transactions/{$expense['id']}")
            ->assertStatus(403);

        $this->actingAs($user2)
            ->putJson("/api/v1/transactions/{$expense['id']}", ['amount' => 200, 'updated_at' => '2025-05-01T15:17:54.120Z'])
            ->assertStatus(403);

        $this->actingAs($user2)
            ->deleteJson("/api/v1/transactions/{$expense['id']}")
            ->assertStatus(403);
    }
}
