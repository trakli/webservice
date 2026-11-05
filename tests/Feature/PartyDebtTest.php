<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PartyDebtTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private int $party;
    private int $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->actingAs($this->owner);
        $this->party = $this->postJson('/api/v1/parties', ['name' => 'Ama'])->assertCreated()->json('data.id');
        $this->wallet = Wallet::factory()->create(['user_id' => $this->owner->id, 'currency' => 'XAF', 'balance' => 100000])->id;
    }

    private function record(string $intent, float $amount, array $extra = [])
    {
        return $this->postJson('/api/v1/transactions', array_replace([
            'intent' => $intent, 'amount' => $amount,
            'type' => in_array($intent, ['loan_given', 'loan_repayment', 'debt_settled']) ? 'expense' : 'income',
            'party_id' => $this->party, 'wallet_id' => $this->wallet,
            'datetime' => '2026-10-01T12:00:00Z',
        ], $extra));
    }

    public function test_lend_collect_and_settle(): void
    {
        $debt = $this->record('loan_given', 20000)->assertCreated()->assertJsonPath('data.debt.remaining', 20000)->json('data.id');
        $this->record('loan_collected', 15000)->assertCreated()->assertJsonPath('data.unapplied', 0);
        $this->getJson('/api/v1/transactions/'.$debt)->assertOk()->assertJsonPath('data.debt.remaining', 5000);
        $this->record('loan_collected', 5000)->assertCreated();
        $this->getJson('/api/v1/transactions/'.$debt)->assertOk()->assertJsonPath('data.debt.status', 'settled');
        $this->getJson('/api/v1/parties/'.$this->party)->assertOk()->assertJsonPath('data.balances', [])->assertJsonPath('data.open_debts', []);
        $this->getJson('/api/v1/parties/'.$this->party.'/debts')->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_foreign_currency_payment_records_actual_rate(): void
    {
        $usd = Wallet::factory()->create(['user_id' => $this->owner->id, 'currency' => 'USD'])->id;
        $debt = $this->record('loan_received', 100, ['wallet_id' => $usd])->assertCreated()->json('data.id');
        $payment = $this->record('loan_repayment', 61000)->assertCreated()->assertJsonCount(1, 'data.settles');
        $this->assertEquals(100, $payment->json('data.settles.0.amount_applied'));
        $this->assertEquals(610, $payment->json('data.settles.0.rate'));
        $payment->assertJsonPath('data.settles.0.rate_source', 'paid')->assertJsonPath('data.unapplied', 0);
        $this->getJson('/api/v1/transactions/'.$debt)->assertOk()->assertJsonPath('data.debt.remaining', 0);
    }

    public function test_inferred_rate_does_not_leave_rounding_excess(): void
    {
        $usd = Wallet::factory()->create(['user_id' => $this->owner->id, 'currency' => 'USD'])->id;
        $this->record('loan_received', 3, ['wallet_id' => $usd])->assertCreated();
        $this->record('loan_repayment', 20)->assertCreated()->assertJsonPath('data.unapplied', 0);
    }

    public function test_only_the_owed_part_is_receivable(): void
    {
        $this->record('loan_given', 30000, ['owed_amount' => 15000])->assertCreated()->assertJsonPath('data.debt.owed', 15000);
        $this->getJson('/api/v1/parties/'.$this->party)->assertOk()->assertJsonPath('data.balances.0.they_owe_me', 15000);
        $this->record('loan_given', 10, ['owed_amount' => 11])->assertUnprocessable();
        $this->record('loan_collected', 10, ['owed_amount' => 1])->assertUnprocessable();
    }

    public function test_oldest_first_edit_and_delete_recompute(): void
    {
        $first = $this->record('loan_given', 100)->assertCreated()->json('data.id');
        $second = $this->record('loan_given', 100, ['datetime' => '2026-10-02T12:00:00Z'])->assertCreated()->json('data.id');
        $payment = $this->record('loan_collected', 150, ['datetime' => '2026-10-03T12:00:00Z'])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/transactions/'.$first)->assertOk()->assertJsonPath('data.debt.remaining', 0);
        $this->getJson('/api/v1/transactions/'.$second)->assertOk()->assertJsonPath('data.debt.remaining', 50);
        $this->putJson('/api/v1/transactions/'.$payment, ['amount' => 50])->assertOk();
        $this->getJson('/api/v1/transactions/'.$first)->assertOk()->assertJsonPath('data.debt.remaining', 50);
        $this->deleteJson('/api/v1/transactions/'.$payment)->assertOk();
        $this->getJson('/api/v1/transactions/'.$first)->assertOk()->assertJsonPath('data.debt.remaining', 100);
        $this->getJson('/api/v1/transactions/'.$second)->assertOk()->assertJsonPath('data.debt.remaining', 100);
    }

    public function test_explicit_settles_and_rejections(): void
    {
        $first = $this->record('loan_given', 100)->assertCreated()->json('data.id');
        $second = $this->record('loan_given', 100)->assertCreated()->json('data.id');
        $this->record('loan_collected', 50, ['settles' => [['debt_transaction_id' => $second, 'amount_applied' => 50]]])->assertCreated();
        $this->getJson('/api/v1/transactions/'.$first)->assertOk()->assertJsonPath('data.debt.remaining', 100);
        $this->record('loan_collected', 100, ['settles' => [['debt_transaction_id' => $second, 'amount_applied' => 51]]])->assertUnprocessable();
        $other = $this->postJson('/api/v1/parties', ['name' => 'Other'])->assertCreated()->json('data.id');
        $this->record('loan_collected', 50, ['party_id' => $other, 'settles' => [['debt_transaction_id' => $first, 'amount_applied' => 50]]])->assertUnprocessable();
        $foreignUser = User::factory()->create();
        $foreignWallet = Wallet::factory()->create(['user_id' => $foreignUser->id, 'currency' => 'XAF']);
        $foreignParty = $foreignUser->parties()->create(['name' => 'Ama']);
        $this->actingAs($foreignUser);
        $foreignDebt = $this->record('loan_given', 100, ['wallet_id' => $foreignWallet->id, 'party_id' => $foreignParty->id])->assertCreated()->json('data.id');
        $this->actingAs($this->owner);
        $this->record('loan_collected', 50, ['settles' => [['debt_transaction_id' => $foreignDebt, 'amount_applied' => 50]]])->assertUnprocessable();
        $this->getJson('/api/v1/parties/'.$foreignParty->id)->assertNotFound();
    }

    public function test_explicit_settles_can_be_replaced_and_cleared(): void
    {
        $first = $this->record('loan_given', 100)->assertCreated()->json('data.id');
        $second = $this->record('loan_given', 100)->assertCreated()->json('data.id');
        $payment = $this->record('loan_collected', 50, ['settles' => [
            ['debt_transaction_id' => $second, 'amount_applied' => 50],
        ]])->assertCreated()->json('data.id');
        $this->putJson('/api/v1/transactions/' . $payment, ['settles' => [
            ['debt_transaction_id' => $first, 'amount_applied' => 50],
        ]])->assertOk()->assertJsonPath('data.settles.0.debt_transaction_id', $first);
        $this->getJson('/api/v1/transactions/' . $second)->assertOk()->assertJsonPath('data.debt.remaining', 100);
        $this->putJson('/api/v1/transactions/' . $payment, ['settles' => []])
            ->assertOk()->assertJsonCount(0, 'data.settles')->assertJsonPath('data.unapplied', 50);
        $this->getJson('/api/v1/transactions/' . $first)->assertOk()->assertJsonPath('data.debt.remaining', 100);
    }

    public function test_party_required_and_unapplied_payment(): void
    {
        foreach (['loan_given', 'loan_received', 'debt_owed', 'loan_collected', 'loan_repayment', 'debt_settled'] as $intent) {
            $this->record($intent, 20, ['party_id' => null])->assertUnprocessable();
        }
        $this->record('loan_collected', 200)->assertCreated()->assertJsonPath('data.unapplied', 200);
    }

    public function test_market_rates_apply_in_debt_currency_and_preserve_explicit_links(): void
    {
        $usd = Wallet::factory()->create(['user_id' => $this->owner->id, 'currency' => 'USD'])->id;
        $first = $this->record('loan_received', 100, ['wallet_id' => $usd])->assertCreated()->json('data.id');
        $second = $this->record('loan_received', 100, ['wallet_id' => $usd])->assertCreated()->json('data.id');
        \App\Models\ExchangeRate::create(['base_currency' => 'USD', 'target_currency' => 'XAF', 'rate' => 600, 'fetched_at' => '2026-09-30 12:00:00']);
        $payment = $this->record('loan_repayment', 90000)->assertCreated()->assertJsonCount(2, 'data.settles');
        $this->assertEquals(100, $payment->json('data.settles.0.amount_applied'));
        $this->assertEquals(50, $payment->json('data.settles.1.amount_applied'));
        $payment->assertJsonPath('data.settles.0.rate_source', 'market');
        $this->deleteJson('/api/v1/transactions/' . $payment->json('data.id'))->assertOk();
        $explicit = $this->record('loan_repayment', 30000, ['settles' => [
            ['debt_transaction_id' => $second, 'amount_applied' => 50],
        ]])->assertCreated()->json('data.id');
        $this->putJson('/api/v1/transactions/' . $first, ['amount' => 200])->assertOk();
        $this->getJson('/api/v1/transactions/' . $explicit)->assertOk()->assertJsonPath('data.settles.0.debt_transaction_id', $second);
        $this->putJson('/api/v1/transactions/' . $second, ['amount' => 40])->assertUnprocessable();
        $this->deleteJson('/api/v1/transactions/' . $second)->assertOk();
        $this->getJson('/api/v1/transactions/' . $explicit)->assertOk()->assertJsonPath('data.unapplied', 30000);
    }

    public function test_payment_without_a_known_rate_is_saved_unapplied(): void
    {
        $dollars = Wallet::factory()->create(['user_id' => $this->owner->id, 'currency' => 'USD', 'balance' => 0])->id;
        $this->record('loan_received', 100, ['wallet_id' => $dollars])->assertCreated();
        $this->record('loan_received', 50, ['wallet_id' => $dollars])->assertCreated();

        $this->record('loan_repayment', 30000)->assertCreated()->assertJsonPath('data.unapplied', 30000);
        $this->getJson('/api/v1/parties/' . $this->party)->assertOk()->assertJsonPath('data.balances.0.i_owe_them', 150);
    }

    public function test_payment_excess_and_party_edits_reallocate(): void
    {
        $debt = $this->record('loan_given', 100)->assertCreated()->json('data.id');
        $payment = $this->record('loan_collected', 150)->assertCreated()->assertJsonPath('data.unapplied', 50)->json('data.id');
        $other = $this->postJson('/api/v1/parties', ['name' => 'Other'])->assertCreated()->json('data.id');
        $this->putJson('/api/v1/transactions/' . $payment, ['party_id' => $other])->assertOk()->assertJsonPath('data.unapplied', 150);
        $this->getJson('/api/v1/transactions/' . $debt)->assertOk()->assertJsonPath('data.debt.remaining', 100);
        $this->putJson('/api/v1/transactions/' . $payment, ['party_id' => $this->party])->assertOk()->assertJsonPath('data.unapplied', 50);
    }

    public function test_position_reports_lent_and_collected(): void
    {
        $this->record('loan_given', 20000)->assertCreated();
        $this->record('loan_collected', 15000)->assertCreated();
        $this->owner->setConfigValue('default-currency', 'XAF', \Whilesmart\ModelConfiguration\Enums\ConfigValueType::String);
        $this->getJson('/api/v1/stats?preset=all_time&section=position')->assertOk()
            ->assertJsonPath('data.position.loan_given', 20000)
            ->assertJsonPath('data.position.loan_collected', 15000)
            ->assertJsonPath('data.position.net_worth_delta', 0)
            ->assertJsonPath('data.position.total_net_worth', 100000);
    }

    public function test_backfill_is_idempotent_and_ignores_missing_parties(): void
    {
        $first = $this->record('loan_received', 100)->assertCreated()->json('data.id');
        $second = $this->record('debt_owed', 100)->assertCreated()->json('data.id');
        $payment = $this->record('loan_repayment', 150)->assertCreated()->json('data.id');
        DB::table('debt_settlements')->delete();
        $legacy = DB::table('transactions')->where('id', $payment)->first();
        $unlinked = (array) $legacy;
        unset($unlinked['id']);
        $unlinked['party_id'] = null;
        $unlinkedId = DB::table('transactions')->insertGetId($unlinked);
        $migration = require database_path('migrations/2026_11_06_000003_backfill_debt_settlements.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseCount('debt_settlements', 2);
        $this->assertDatabaseMissing('debt_settlements', ['payment_transaction_id' => $unlinkedId]);
        $this->assertDatabaseHas('debt_settlements', ['debt_transaction_id' => $first, 'payment_transaction_id' => $payment, 'amount_applied' => 100]);
        $this->assertDatabaseHas('debt_settlements', ['debt_transaction_id' => $second, 'payment_transaction_id' => $payment, 'amount_applied' => 50]);
    }
}
