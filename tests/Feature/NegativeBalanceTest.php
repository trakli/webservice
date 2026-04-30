<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NegativeBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function setAllowNegativeBalance(User $user, bool $value): void
    {
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/configurations', [
                'key' => 'wallets-allow-negative-balance',
                'type' => 'bool',
                'value' => $value,
            ])
            ->assertStatus(201);
    }

    public function test_transfer_fails_when_insufficient_funds_and_config_disabled()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 10.00]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 0]);

        $this->setAllowNegativeBalance($user, false);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/transfers', [
            'amount' => 50.00,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.setting_key', 'wallets-allow-negative-balance');
        $this->assertEquals(10.00, $fromWallet->fresh()->balance);
        $this->assertEquals(0.0, (float) $toWallet->fresh()->balance);
    }

    public function test_transfer_fails_when_insufficient_funds_and_config_unset()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 10.00]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 0]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/transfers', [
            'amount' => 50.00,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.setting_key', 'wallets-allow-negative-balance');
    }

    public function test_transfer_succeeds_when_insufficient_funds_and_config_enabled()
    {
        $user = User::factory()->create();
        $fromWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 10.00]);
        $toWallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 0]);

        $this->setAllowNegativeBalance($user, true);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/transfers', [
            'amount' => 50.00,
            'from_wallet_id' => $fromWallet->id,
            'to_wallet_id' => $toWallet->id,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(-40.00, (float) $fromWallet->fresh()->balance);
        $this->assertEquals(50.00, (float) $toWallet->fresh()->balance);
    }
}
