<?php

namespace Tests\Feature;

use App\Models\Refund;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->wallet = Wallet::factory()->create(['user_id' => $this->user->id]);
    }

    public function test_user_can_mark_income_as_refund(): void
    {
        $income = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'type' => 'income',
            'amount' => 50,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/transactions/{$income->id}/refund");

        $response->assertOk();
        $this->assertDatabaseHas('refunds', ['refund_transaction_id' => $income->id]);
        $this->assertTrue($income->refresh()->isRefund());
    }

    public function test_user_can_mark_income_as_refund_linked_to_original(): void
    {
        $original = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'type' => 'expense',
            'amount' => 200,
        ]);
        $income = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'type' => 'income',
            'amount' => 40,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/transactions/{$income->id}/refund",
            ['original_transaction_id' => $original->id]
        );

        $response->assertOk();
        $this->assertDatabaseHas('refunds', [
            'refund_transaction_id' => $income->id,
            'original_transaction_id' => $original->id,
        ]);
    }

    public function test_cannot_mark_expense_as_refund(): void
    {
        $expense = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'type' => 'expense',
            'amount' => 100,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/transactions/{$expense->id}/refund");

        $response->assertStatus(422);
    }

    public function test_cannot_link_refund_to_another_users_expense(): void
    {
        $other = User::factory()->create();
        $otherWallet = Wallet::factory()->create(['user_id' => $other->id]);
        $theirExpense = Transaction::factory()->create([
            'user_id' => $other->id,
            'wallet_id' => $otherWallet->id,
            'type' => 'expense',
            'amount' => 100,
        ]);

        $income = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'type' => 'income',
            'amount' => 40,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/transactions/{$income->id}/refund",
            ['original_transaction_id' => $theirExpense->id]
        );

        $response->assertStatus(422);
        $this->assertDatabaseMissing('refunds', ['refund_transaction_id' => $income->id]);
    }

    public function test_cannot_link_refund_to_income_transaction(): void
    {
        $anotherIncome = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'type' => 'income',
            'amount' => 200,
        ]);
        $income = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'type' => 'income',
            'amount' => 40,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/transactions/{$income->id}/refund",
            ['original_transaction_id' => $anotherIncome->id]
        );

        $response->assertStatus(422);
    }

    public function test_unmark_refund_removes_record(): void
    {
        $income = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'type' => 'income',
            'amount' => 50,
        ]);
        Refund::create(['refund_transaction_id' => $income->id]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/transactions/{$income->id}/refund")
            ->assertStatus(204);

        $this->assertDatabaseMissing('refunds', ['refund_transaction_id' => $income->id]);
    }

    public function test_marking_is_idempotent_and_updates_link(): void
    {
        $income = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'type' => 'income',
            'amount' => 50,
        ]);
        $original = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'type' => 'expense',
            'amount' => 200,
        ]);

        $this->actingAs($this->user)->postJson("/api/v1/transactions/{$income->id}/refund");
        $this->actingAs($this->user)->postJson(
            "/api/v1/transactions/{$income->id}/refund",
            ['original_transaction_id' => $original->id]
        );

        $this->assertSame(1, Refund::where('refund_transaction_id', $income->id)->count());
        $this->assertSame($original->id, $income->refresh()->refund->original_transaction_id);
    }

    public function test_cannot_mark_another_users_transaction(): void
    {
        $other = User::factory()->create();
        $otherWallet = Wallet::factory()->create(['user_id' => $other->id]);
        $theirIncome = Transaction::factory()->create([
            'user_id' => $other->id,
            'wallet_id' => $otherWallet->id,
            'type' => 'income',
            'amount' => 50,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/transactions/{$theirIncome->id}/refund");

        $response->assertStatus(404);
    }
}
