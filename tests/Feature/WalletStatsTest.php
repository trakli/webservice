<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletStatsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Wallet $wallet;

    private float $initialBalance = 1000.00;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->wallet = Wallet::factory()->create([
            'user_id' => $this->user->id,
            'balance' => $this->initialBalance,
        ]);
    }

    public function test_can_retrieve_wallet()
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/wallets/{$this->wallet->id}");

        $response->assertStatus(200);
        $this->assertEquals($this->wallet->id, $response->json('data.id'));
        $this->assertEquals($this->initialBalance, (float) $response->json('data.balance'));
    }

    public function test_can_create_income_transaction()
    {
        $transactionData = [
            'wallet_id' => $this->wallet->id,
            'amount' => 100.00,
            'type' => 'income',
            'description' => 'Test income',
            'datetime' => now()->toIso8601String(),
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/transactions', $transactionData);

        $response->assertStatus(201);
        $this->assertDatabaseHas('transactions', [
            'wallet_id' => $this->wallet->id,
            'amount' => 100.00,
            'type' => 'income',
        ]);

        // Verify wallet balance was updated
        $this->assertEquals(
            $this->initialBalance + 100.00,
            $this->wallet->fresh()->balance
        );
    }

    public function test_wallet_stats_show_correct_income_and_expense()
    {
        $expectedIncome = 0;
        $expectedExpense = 0;

        // Create 5 income transactions
        for ($i = 1; $i <= 5; $i++) {
            $amount = 100.00 * $i;
            $this->actingAs($this->user)
                ->postJson('/api/v1/transactions', [
                    'wallet_id' => $this->wallet->id,
                    'amount' => $amount,
                    'type' => 'income',
                    'description' => 'Test income '.$i,
                    'datetime' => now()->toIso8601String(),
                ]);
            $expectedIncome += $amount;
        }

        // Create 5 expense transactions
        for ($i = 1; $i <= 5; $i++) {
            $amount = 50.00 * $i;
            $this->actingAs($this->user)
                ->postJson('/api/v1/transactions', [
                    'wallet_id' => $this->wallet->id,
                    'amount' => $amount,
                    'type' => 'expense',
                    'description' => 'Test expense '.$i,
                    'datetime' => now()->toIso8601String(),
                ]);
            $expectedExpense += $amount;
        }

        // Get wallet with stats via API
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/wallets/{$this->wallet->id}");

        $response->assertStatus(200);
        $this->assertEquals($expectedIncome, $response->json('data.stats.total_income'));
        $this->assertEquals($expectedExpense, $response->json('data.stats.total_expense'));
    }

    public function test_wallet_stats_include_transfer_transactions()
    {
        $expectedIncome = 0;
        $expectedExpense = 0;
        $transferAmount = 200.00;

        // Create a second wallet for transfers
        $secondWallet = Wallet::factory()->create([
            'user_id' => $this->user->id,
            'balance' => 10000.00,
        ]);

        // Create a transfer (should create an expense in source wallet)
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/transfers', [
                'from_wallet_id' => $this->wallet->id,
                'to_wallet_id' => $secondWallet->id,
                'amount' => $transferAmount,
                'description' => 'Test transfer',
                'datetime' => now()->toIso8601String(),
            ]);

        $response->assertStatus(201);
        $expectedExpense += $transferAmount;

        // Create an income transaction
        $incomeAmount = 500.00;
        $this->actingAs($this->user)
            ->postJson('/api/v1/transactions', [
                'wallet_id' => $this->wallet->id,
                'amount' => $incomeAmount,
                'type' => 'income',
                'description' => 'Test income',
                'datetime' => now()->toIso8601String(),
            ]);
        $expectedIncome += $incomeAmount;

        // Get wallet with stats
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/wallets/{$this->wallet->id}");

        $response->assertStatus(200);

        $stats = $response->json('data.stats');
        $this->assertEquals($expectedIncome, (float) $stats['total_income'], 'Total income does not match');
        $this->assertEquals($expectedExpense, (float) $stats['total_expense'], 'Total expense does not match');

        // Verify final balance
        $expectedBalance = $this->initialBalance + $expectedIncome - $expectedExpense;
        $this->assertEquals($expectedBalance, (float) $response->json('data.balance'));
    }
}
