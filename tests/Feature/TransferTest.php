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
use Whilesmart\UserDevices\Models\Device;

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

    public function test_api_user_can_list_transfers()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
        ]);

        $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 50,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/transfers');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.data'));
    }

    public function test_api_user_can_list_transfers_without_client_id()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id]);

        $deviceId = Uuid::uuid4()->toString();
        $clientId = $deviceId.':'.Uuid::uuid4()->toString();

        $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'client_id' => $clientId,
        ]);

        $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 50,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/transfers?no_client_id=1');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals(50, $response->json('data.data.0.amount'));
    }

    public function test_api_user_can_attach_client_id_to_existing_transfer()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id]);

        $createResponse = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
        ]);

        $createResponse->assertStatus(201);
        $transferId = $createResponse->json('data.id');

        $transfer = Transfer::find($transferId);
        $this->assertNull($transfer->client_generated_id);

        $deviceId = Uuid::uuid4()->toString();
        $randomId = Uuid::uuid4()->toString();
        $clientId = $deviceId.':'.$randomId;

        $updateResponse = $this->actingAs($user)->putJson("/api/v1/transfers/{$transferId}", [
            'client_id' => $clientId,
        ]);

        $updateResponse->assertStatus(200);

        $syncState = ModelSyncState::where('syncable_type', Transfer::class)
            ->where('syncable_id', $transferId)
            ->first();

        $this->assertNotNull($syncState);
        $this->assertEquals($randomId, $syncState->client_generated_id);
    }

    public function test_api_user_cannot_overwrite_existing_client_id()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id]);

        $deviceId = Uuid::uuid4()->toString();
        $originalRandomId = Uuid::uuid4()->toString();
        $originalClientId = $deviceId.':'.$originalRandomId;

        $createResponse = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'client_id' => $originalClientId,
        ]);

        $createResponse->assertStatus(201);
        $transferId = $createResponse->json('data.id');

        $newRandomId = Uuid::uuid4()->toString();
        $newClientId = $deviceId.':'.$newRandomId;

        $this->actingAs($user)->putJson("/api/v1/transfers/{$transferId}", [
            'client_id' => $newClientId,
        ]);

        $syncState = ModelSyncState::where('syncable_type', Transfer::class)
            ->where('syncable_id', $transferId)
            ->first();

        $this->assertEquals($originalRandomId, $syncState->client_generated_id);
    }

    public function test_api_user_cannot_update_another_users_transfer()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user1->id, 'balance' => 1000]);
        $toWallet = Wallet::factory()->create(['user_id' => $user1->id]);

        $createResponse = $this->actingAs($user1)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
        ]);

        $transferId = $createResponse->json('data.id');

        $deviceId = Uuid::uuid4()->toString();
        $clientId = $deviceId.':'.Uuid::uuid4()->toString();

        $updateResponse = $this->actingAs($user2)->putJson("/api/v1/transfers/{$transferId}", [
            'client_id' => $clientId,
        ]);

        $updateResponse->assertStatus(404);
    }

    public function test_transfer_stores_datetime_and_propagates_to_transactions()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'datetime' => '2025-06-15T10:30:00.000Z',
        ]);

        $response->assertStatus(201);

        $transfer = Transfer::first();
        $this->assertEquals('2025-06-15 10:30:00', $transfer->datetime->format('Y-m-d H:i:s'));

        $expenseTransaction = Transaction::where('wallet_id', $fromWallet->id)->first();
        $incomeTransaction = Transaction::where('wallet_id', $toWallet->id)->first();

        $this->assertEquals('2025-06-15 10:30:00', $expenseTransaction->datetime->format('Y-m-d H:i:s'));
        $this->assertEquals('2025-06-15 10:30:00', $incomeTransaction->datetime->format('Y-m-d H:i:s'));
    }

    public function test_transfer_without_datetime_defaults_to_now()
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

        $transfer = Transfer::first();
        $this->assertNotNull($transfer->datetime);
    }

    public function test_transfer_with_expense_and_income_transaction_client_ids_links_existing_transactions()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id]);

        $deviceToken = Uuid::uuid4()->toString();
        $device = Device::create(['token' => $deviceToken, 'deviceable_id' => $user->id, 'deviceable_type' => get_class($user)]);

        // Simulate transactions synced first (mobile created them before the transfer)
        $expenseTransaction = $user->transactions()->create([
            'amount' => 100,
            'datetime' => now(),
            'type' => TransactionType::EXPENSE->value,
            'description' => 'Transfer expense',
            'wallet_id' => $fromWallet->id,
        ]);
        $expenseRandomId = Uuid::uuid4()->toString();
        $expenseTransaction->syncState()->updateOrCreate([], [
            'client_generated_id' => $expenseRandomId,
            'device_id' => $device->id,
        ]);

        $incomeTransaction = $user->transactions()->create([
            'amount' => 100,
            'datetime' => now(),
            'type' => TransactionType::INCOME->value,
            'description' => 'Transfer income',
            'wallet_id' => $toWallet->id,
        ]);
        $incomeRandomId = Uuid::uuid4()->toString();
        $incomeTransaction->syncState()->updateOrCreate([], [
            'client_generated_id' => $incomeRandomId,
            'device_id' => $device->id,
        ]);

        $transactionCountBefore = Transaction::where('user_id', $user->id)->count();

        // Now sync the transfer with client IDs referencing the existing transactions
        $response = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'expense_transaction_client_id' => $deviceToken . ':' . $expenseRandomId,
            'income_transaction_client_id' => $deviceToken . ':' . $incomeRandomId,
        ]);

        $response->assertStatus(201);

        // No new transactions should have been created
        $this->assertEquals($transactionCountBefore, Transaction::where('user_id', $user->id)->count());

        // Existing transactions should now be linked to the transfer
        $transfer = Transfer::first();
        $expenseTransaction->refresh();
        $incomeTransaction->refresh();

        $this->assertEquals($transfer->id, $expenseTransaction->transfer_id);
        $this->assertEquals($transfer->id, $incomeTransaction->transfer_id);
    }

    public function test_transfer_with_unknown_client_ids_creates_new_transactions()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id]);

        $deviceToken = Uuid::uuid4()->toString();
        $expenseClientId = $deviceToken . ':' . Uuid::uuid4()->toString();
        $incomeClientId = $deviceToken . ':' . Uuid::uuid4()->toString();

        $response = $this->actingAs($user)->postJson('/api/v1/transfers', [
            'amount' => 100,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
            'expense_transaction_client_id' => $expenseClientId,
            'income_transaction_client_id' => $incomeClientId,
        ]);

        $response->assertStatus(201);

        // New transactions should be created since client IDs don't match existing ones
        $transfer = Transfer::first();
        $transactions = Transaction::where('transfer_id', $transfer->id)->get();
        $this->assertCount(2, $transactions);
    }
}
