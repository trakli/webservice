<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = \App\Models\User::factory()->create();
        $this->wallet = Wallet::factory()->create([
            'user_id' => $this->user->id,
            'balance' => 1000.00,
        ]);
    }

    public function test_wallet_balance_updates_on_transaction_creation()
    {
        $transaction = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'amount' => 100.50,
            'type' => 'expense',
        ]);

        $this->assertEquals(899.50, $this->wallet->fresh()->balance);

        $transaction = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'amount' => 50.25,
            'type' => 'income',
        ]);

        $this->assertEquals(949.75, $this->wallet->fresh()->balance);
    }

    public function test_wallet_balance_updates_on_transaction_update()
    {
        $transaction = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'amount' => 100.00,
            'type' => 'expense',
        ]);

        $this->assertEquals(900.00, $this->wallet->fresh()->balance);

        $transaction->update(['amount' => 50.00]);
        $this->assertEquals(950.00, $this->wallet->fresh()->balance);

        $transaction->update(['type' => 'income']);
        $this->assertEquals(1050.00, $this->wallet->fresh()->balance);
    }

    public function test_wallet_balance_updates_on_transaction_deletion()
    {
        $transaction = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'amount' => 100.00,
            'type' => 'expense',
        ]);

        $this->assertEquals(900.00, $this->wallet->fresh()->balance);

        $transaction->delete();
        $this->assertEquals(1000.00, $this->wallet->fresh()->balance);
    }

    public function test_wallet_balance_handles_multiple_transactions()
    {
        $transactions = Transaction::factory()->count(5)->create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'amount' => 10.00,
            'type' => 'expense',
        ]);

        $this->assertEquals(950.00, $this->wallet->fresh()->balance);

        $transactions->first()->update(['amount' => 20.00]);
        $this->assertEquals(940.00, $this->wallet->fresh()->balance);

        $transactions->first()->delete();
        $this->assertEquals(960.00, $this->wallet->fresh()->balance);
    }
}
