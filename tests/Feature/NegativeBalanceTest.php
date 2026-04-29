<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Whilesmart\ModelConfiguration\Enums\ConfigValueType;

class NegativeBalanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that a transaction fails if balance is insufficient and config is OFF.
     */
    public function test_transaction_fails_when_insufficient_funds_and_config_disabled()
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 10.00]);

        // Explicitly set allow-negative-balance to false
        $user->setConfigValue('allow-negative-balance', false, ConfigValueType::Boolean);

        $payload = [
            'amount' => 50.00, // More than the 10.00 balance
            'type' => 'expense',
            'wallet_id' => $wallet->id,
            'description' => 'Overdraft attempt',
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/transactions', $payload);

        // It should fail because the TransferService (or Controller) throws an error
        $response->assertStatus(422);
        $this->assertEquals(10.00, $wallet->fresh()->balance);
    }

    /**
     * Test that a transaction succeeds if balance is insufficient but config is ON.
     */
    public function test_transaction_succeeds_when_insufficient_funds_and_config_enabled()
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 10.00]);

        // Enable negative balance for this user
        $user->setConfigValue('allow-negative-balance', true, ConfigValueType::Boolean);

        $payload = [
            'amount' => 50.00,
            'type' => 'expense',
            'wallet_id' => $wallet->id,
            'description' => 'Allowed overdraft',
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/transactions', $payload);

        // Assert success
        $response->assertStatus(201);

        // Assert the warning exists in the response JSON
        // $response->assertJsonPath('warning', __('Your account is now in a negative balance.'));

        // Assert the database reflects a negative balance (-40)
        $this->assertEquals(-40.00, (float)$wallet->fresh()->balance);
    }
}
