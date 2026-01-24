<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Models\ModelSyncState;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class TransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_user_can_transfer_between_wallets_with_the_same_currency()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('transfers', [
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'amount' => 100,
        ]);

        $fromWallet->refresh();
        $toWallet->refresh();

        $this->assertEquals(900, $fromWallet->balance);
        $this->assertEquals(100, $toWallet->balance);

        $fromTransaction = Transaction::where('wallet_id', $fromWallet->id)->first();
        $this->assertNotNull($fromTransaction);
        $this->assertEquals(TransactionType::EXPENSE->value, $fromTransaction->type);
        $this->assertEquals('Money transfer from USD wallet to USD wallet', $fromTransaction->description);
        $this->assertEquals(100, $fromTransaction->amount);

        $toTransaction = Transaction::where('wallet_id', $toWallet->id)->first();
        $this->assertNotNull($toTransaction);
        $this->assertEquals(TransactionType::INCOME->value, $toTransaction->type);
        $this->assertEquals('Money transfer from USD wallet to USD wallet', $toTransaction->description);
        $this->assertEquals(100, $toTransaction->amount);
    }

    public function test_api_user_can_not_transfer_from_a_wallet_with_insufficient_balance()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 50]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
        ]);

        $response->assertStatus(400);
        $response->assertJson(['message' => 'Source wallet has insufficient balance']);
    }

    public function test_api_user_can_not_transfer_from_two_wallets_with_different_currencies_without_an_exchange_rate()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000, 'currency' => 'USD']);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);

        $response = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
        ]);

        $response->assertStatus(400);
        $response->assertJson(['message' => 'Please fill in an exchange rate']);
    }

    public function test_api_user_can_transfer_from_two_wallets_with_different_currencies()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000, 'currency' => 'USD']);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 100, 'currency' => 'EUR']);

        $response = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'exchange_rate' => 2.5,
        ]);

        $response->assertStatus(201);

        $fromWallet->refresh();
        $toWallet->refresh();

        $exchangedAmount = 100 * 2.5;
        $newBalance = 100 + $exchangedAmount;

        $this->assertEquals(900, $fromWallet->balance);
        $this->assertEquals($newBalance, $toWallet->balance);

        $fromTransaction = Transaction::where('wallet_id', $fromWallet->id)->first();
        $this->assertNotNull($fromTransaction);
        $this->assertEquals(TransactionType::EXPENSE->value, $fromTransaction->type);
        $this->assertEquals('Money transfer from USD wallet to EUR wallet', $fromTransaction->description);
        $this->assertEquals(100, $fromTransaction->amount);

        $toTransaction = Transaction::where('wallet_id', $toWallet->id)->first();
        $this->assertNotNull($toTransaction);
        $this->assertEquals(TransactionType::INCOME->value, $toTransaction->type);
        $this->assertEquals('Money transfer from USD wallet to EUR wallet', $toTransaction->description);
        $this->assertEquals($exchangedAmount, $toTransaction->amount);
    }

    public function test_api_user_can_not_transfer_from_an_invalid_wallet()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);

        $response = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => 999, // Non-existent wallet ID
        ]);

        $response->assertStatus(422);
    }

    public function test_api_user_can_not_transfer_from_another_users_wallet()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);

        $user2 = User::factory()->create();
        $toWallet = Wallet::factory()->create(['user_id' => $user2->id, 'balance' => 1000]);

        $response = $this->actingAs($user2)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
        ]);

        $response->assertStatus(400);
    }

    public function test_api_user_cannot_transfer_negative_amount()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => -100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_api_user_cannot_transfer_zero_amount()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 0,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_transfer_with_client_id_stores_sync_state()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id]);

        $deviceId = Uuid::uuid4()->toString();
        $randomId = Uuid::uuid4()->toString();
        $clientId = $deviceId.':'.$randomId;

        $response = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'client_id' => $clientId,
        ]);

        $response->assertStatus(201);

        $transfer = Transfer::first();
        $this->assertNotNull($transfer);

        $syncState = ModelSyncState::where('syncable_type', Transfer::class)
            ->where('syncable_id', $transfer->id)
            ->first();

        $this->assertNotNull($syncState);
        $this->assertEquals($randomId, $syncState->client_generated_id);
    }

    public function test_duplicate_transfer_request_returns_existing_transfer()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id]);

        $deviceId = Uuid::uuid4()->toString();
        $randomId = Uuid::uuid4()->toString();
        $clientId = $deviceId.':'.$randomId;

        $firstResponse = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'client_id' => $clientId,
        ]);

        $firstResponse->assertStatus(201);
        $firstTransferId = $firstResponse->json('data.id');

        $secondResponse = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'client_id' => $clientId,
        ]);

        $secondResponse->assertStatus(200);
        $secondTransferId = $secondResponse->json('data.id');

        $this->assertEquals($firstTransferId, $secondTransferId);
        $this->assertCount(1, Transfer::all());

        $fromWallet->refresh();
        $this->assertEquals(900, $fromWallet->balance);
    }

    public function test_transfer_transactions_get_client_ids_when_transfer_has_client_id()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id]);

        $deviceId = Uuid::uuid4()->toString();
        $randomId = Uuid::uuid4()->toString();
        $clientId = $deviceId.':'.$randomId;

        $response = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'client_id' => $clientId,
        ]);

        $response->assertStatus(201);

        $expenseTransaction = Transaction::where('wallet_id', $fromWallet->id)->first();
        $incomeTransaction = Transaction::where('wallet_id', $toWallet->id)->first();

        $expenseSyncState = ModelSyncState::where('syncable_type', Transaction::class)
            ->where('syncable_id', $expenseTransaction->id)
            ->first();

        $incomeSyncState = ModelSyncState::where('syncable_type', Transaction::class)
            ->where('syncable_id', $incomeTransaction->id)
            ->first();

        $this->assertNotNull($expenseSyncState);
        $this->assertNotNull($expenseSyncState->client_generated_id);
        $this->assertNotNull($incomeSyncState);
        $this->assertNotNull($incomeSyncState->client_generated_id);

        $this->assertNotEquals(
            $expenseSyncState->client_generated_id,
            $incomeSyncState->client_generated_id
        );
    }

    public function test_transfer_without_client_id_does_not_prevent_duplicates()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id]);

        $firstResponse = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
        ]);

        $firstResponse->assertStatus(201);

        $secondResponse = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
        ]);

        $secondResponse->assertStatus(201);

        $this->assertCount(2, Transfer::all());
    }

    public function test_transfer_with_invalid_client_id_format_fails_validation()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'client_id' => 'invalid-format',
        ]);

        $response->assertStatus(422);
    }

    public function test_different_client_ids_create_separate_transfers()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id]);

        $deviceId = Uuid::uuid4()->toString();

        $firstClientId = $deviceId.':'.Uuid::uuid4()->toString();
        $firstResponse = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'client_id' => $firstClientId,
        ]);

        $firstResponse->assertStatus(201);

        $secondClientId = $deviceId.':'.Uuid::uuid4()->toString();
        $secondResponse = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'client_id' => $secondClientId,
        ]);

        $secondResponse->assertStatus(201);

        $this->assertCount(2, Transfer::all());
        $this->assertNotEquals(
            $firstResponse->json('data.id'),
            $secondResponse->json('data.id')
        );
    }
}
