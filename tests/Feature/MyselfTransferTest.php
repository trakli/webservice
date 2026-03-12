<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\Transfer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class MyselfTransferTest extends TestCase
{
    use DatabaseMigrations;

    public function test_income_from_myself_is_handled_as_transfer()
    {
        // 1. Setup Data
        $user = User::factory()->create();
        $walletSource = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
        $walletDest = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 0]);
        
        // Create the "Myself" Party
        $myselfParty = Party::factory()->create([
            'user_id' => $user->id,
            'is_myself' => true,
            'name' => 'My Personal Cash'
        ]);

        $fakeClientId = \Illuminate\Support\Str::uuid() . ':' . \Illuminate\Support\Str::uuid();
        // 2. The Payload
        $payload = [
            'amount' => 200,                // Validator needs 'amount'
            'type' => 'income',             // Validator needs 'type'
            'party_id' => $myselfParty->id, // Triggers the 'is_myself' logic
            'wallet_id' => $walletDest->id, // Becomes 'toWallet'
            'from_wallet_id' => $walletSource->id, // Becomes 'fromWallet'
            'client_id' => $fakeClientId, // To ensure idempotency in tests
            'description' => 'Moving cash to bank',
            'datetime' => now()->toIso8601String(),
        ];

        // 3. Act
        $response = $this->actingAs($user, 'sanctum')
                         ->postJson('/api/v1/transactions', $payload);

        // 4. Assertions
        $response->assertStatus(201);

        // Check if a Transfer record was created instead of just a loose transaction
        $this->assertDatabaseHas('transfers', [
            'from_wallet_id' => $walletSource->id,
            'to_wallet_id' => $walletDest->id,
            'amount' => 200,
        ]);

        // Check balances
        $this->assertEquals(800, $walletSource->fresh()->balance);
        $this->assertEquals(200, $walletDest->fresh()->balance);
        
        // Ensure the transaction is linked to a transfer
        $this->assertNotNull(Transaction::where('amount', 200)->first()->transfer_id);
    }

    public function test_normal_income_stays_as_transaction()
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 0]);
        $normalParty = Party::factory()->create([
            'is_myself' => false,
            'user_id' => $user->id,
            ]);

        $payload = [
            'amount' => 100,
            'type' => 'income',
            'party_id' => $normalParty->id,
            'wallet_id' => $wallet->id,
        ];

        $this->actingAs($user, 'sanctum')
             ->postJson('/api/v1/transactions', $payload)
             ->assertStatus(201);

        // Should NOT create a transfer
        $this->assertEquals(0, Transfer::count());
        $this->assertEquals(100, $wallet->fresh()->balance);
    }
}