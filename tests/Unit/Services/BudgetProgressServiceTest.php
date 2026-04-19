<?php

namespace Tests\Unit\Services;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Group;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\BudgetProgressService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    private BudgetProgressService $service;

    private User $user;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BudgetProgressService::class);
        $this->user = User::factory()->create();
        $this->wallet = Wallet::factory()->create(['user_id' => $this->user->id]);
    }

    public function test_category_only_budget_sums_net_of_refunds(): void
    {
        $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense']);

        $budget = Budget::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
            'amount' => 500,
            'period_type' => Budget::PERIOD_MONTHLY,
            'start_date' => CarbonImmutable::now()->startOfMonth()->toDateString(),
        ]);
        $budget->categories()->attach($category);

        $this->attachExpense($category, 200);
        $this->attachExpense($category, 100);

        // Marking this income as a refund is what makes it count — an
        // un-marked income would be ignored even if same category.
        $refund = $this->attachRefund($category, 50);
        $refund->markAsRefund();

        $progress = $this->service->compute($budget);

        $this->assertEquals(300.0, $progress['gross_spent']);
        $this->assertEquals(50.0, $progress['refunds']);
        $this->assertEquals(250.0, $progress['net_spent']);
        $this->assertEquals(250.0, $progress['remaining']);
        $this->assertEquals('on_track', $progress['status']);
    }

    public function test_threshold_crossed_marks_near_limit(): void
    {
        $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense']);

        $budget = Budget::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
            'amount' => 100,
            'threshold_percent' => 80,
            'forecast_alerts_enabled' => false,
            'period_type' => Budget::PERIOD_MONTHLY,
            'start_date' => CarbonImmutable::now()->startOfMonth()->toDateString(),
        ]);
        $budget->categories()->attach($category);

        $this->attachExpense($category, 85);

        $progress = $this->service->compute($budget);

        $this->assertTrue($progress['is_threshold_crossed']);
        $this->assertEquals('near_limit', $progress['status']);
    }

    public function test_wallet_scoped_budget_counts_wallet_spend(): void
    {
        $budget = Budget::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
            'amount' => 1000,
            'period_type' => Budget::PERIOD_MONTHLY,
            'start_date' => CarbonImmutable::now()->startOfMonth()->toDateString(),
        ]);
        $budget->wallets()->attach($this->wallet);

        $other = Wallet::factory()->create(['user_id' => $this->user->id]);

        $this->txn(walletId: $this->wallet->id, type: 'expense', amount: 200);
        $this->txn(walletId: $other->id, type: 'expense', amount: 500);

        $progress = $this->service->compute($budget);

        $this->assertEquals(200.0, $progress['net_spent']);
    }

    public function test_group_scoped_budget_counts_grouped_transactions(): void
    {
        $group = Group::factory()->create(['user_id' => $this->user->id]);

        $budget = Budget::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
            'amount' => 1000,
            'period_type' => Budget::PERIOD_MONTHLY,
            'start_date' => CarbonImmutable::now()->startOfMonth()->toDateString(),
        ]);
        $budget->groups()->attach($group);

        $txn = $this->txn(walletId: $this->wallet->id, type: 'expense', amount: 300);
        $txn->groups()->attach($group);

        $ignored = $this->txn(walletId: $this->wallet->id, type: 'expense', amount: 400);

        $progress = $this->service->compute($budget);

        $this->assertEquals(300.0, $progress['net_spent']);
    }

    public function test_forecast_breach_when_pace_exceeds_limit(): void
    {
        $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense']);

        $startOfMonth = CarbonImmutable::now()->startOfMonth();

        $budget = Budget::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
            'amount' => 100,
            'threshold_percent' => 80,
            'forecast_alerts_enabled' => true,
            'period_type' => Budget::PERIOD_MONTHLY,
            'start_date' => $startOfMonth->toDateString(),
        ]);
        $budget->categories()->attach($category);

        $txn = $this->txn(walletId: $this->wallet->id, type: 'expense', amount: 60);
        $txn->categories()->attach($category);
        $txn->datetime = $startOfMonth->addDays(5);
        $txn->save();

        // Reference: 10 days in, 60 spent, pace → ~180 over 30 days
        $reference = $startOfMonth->addDays(10);

        $progress = $this->service->compute($budget, $reference);

        $this->assertTrue($progress['is_forecast_breach']);
    }

    public function test_custom_period_uses_explicit_end_date(): void
    {
        $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense']);

        $budget = Budget::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
            'amount' => 800,
            'period_type' => Budget::PERIOD_CUSTOM,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-14',
        ]);
        $budget->categories()->attach($category);

        $inside = $this->txn(walletId: $this->wallet->id, type: 'expense', amount: 100, datetime: '2026-08-05 10:00:00');
        $inside->categories()->attach($category);

        $outside = $this->txn(walletId: $this->wallet->id, type: 'expense', amount: 500, datetime: '2026-08-20 10:00:00');
        $outside->categories()->attach($category);

        $progress = $this->service->compute($budget, CarbonImmutable::parse('2026-08-15'));

        $this->assertEquals(100.0, $progress['net_spent']);
        $this->assertEquals('2026-08-01', $progress['period_start']);
        $this->assertEquals('2026-08-14', $progress['period_end']);
    }

    public function test_budget_with_no_targets_sums_all_expenses_and_ignores_income(): void
    {
        $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense']);

        $budget = Budget::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
            'amount' => 1000,
            'period_type' => Budget::PERIOD_MONTHLY,
            'start_date' => CarbonImmutable::now()->startOfMonth()->toDateString(),
        ]);
        // No targets attached — the budget caps total period spend.

        $this->attachExpense($category, 200);
        $this->txn(walletId: $this->wallet->id, type: 'expense', amount: 80);
        // Salary, freelance, etc. — generic income should NOT be treated as
        // a refund when the budget has no category scope to interpret it.
        $this->txn(walletId: $this->wallet->id, type: 'income', amount: 1500);

        $progress = $this->service->compute($budget);

        $this->assertEquals(280.0, $progress['gross_spent']);
        $this->assertEquals(0.0, $progress['refunds']);
        $this->assertEquals(280.0, $progress['net_spent']);
    }

    public function test_wallet_only_budget_does_not_count_income_as_refunds(): void
    {
        $budget = Budget::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
            'amount' => 1000,
            'period_type' => Budget::PERIOD_MONTHLY,
            'start_date' => CarbonImmutable::now()->startOfMonth()->toDateString(),
        ]);
        $budget->wallets()->attach($this->wallet);

        $this->txn(walletId: $this->wallet->id, type: 'expense', amount: 200);
        // Salary into the wallet — must not zero out the budget.
        $this->txn(walletId: $this->wallet->id, type: 'income', amount: 3000);

        $progress = $this->service->compute($budget);

        $this->assertEquals(200.0, $progress['gross_spent']);
        $this->assertEquals(0.0, $progress['refunds']);
        $this->assertEquals(200.0, $progress['net_spent']);
    }

    public function test_only_explicit_refunds_reduce_net_spent(): void
    {
        $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense']);

        $budget = Budget::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
            'amount' => 500,
            'period_type' => Budget::PERIOD_MONTHLY,
            'start_date' => CarbonImmutable::now()->startOfMonth()->toDateString(),
        ]);
        $budget->categories()->attach($category);

        $this->attachExpense($category, 300);

        // Two income transactions in the same category. Only one is flagged
        // as a refund; the other is unrelated income that happens to share
        // the category tag (gift, sale, whatever) and must not subtract.
        $flagged = $this->attachRefund($category, 50);
        $flagged->markAsRefund();
        $this->attachRefund($category, 80);

        $progress = $this->service->compute($budget);

        $this->assertEquals(300.0, $progress['gross_spent']);
        $this->assertEquals(50.0, $progress['refunds']);
        $this->assertEquals(250.0, $progress['net_spent']);
    }

    public function test_refund_linked_to_original_tracks_same_expense(): void
    {
        $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense']);

        $budget = Budget::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
            'amount' => 500,
            'period_type' => Budget::PERIOD_MONTHLY,
            'start_date' => CarbonImmutable::now()->startOfMonth()->toDateString(),
        ]);
        $budget->categories()->attach($category);

        $original = $this->attachExpense($category, 120);
        $refund = $this->attachRefund($category, 40);
        $refund->markAsRefund($original);

        $this->assertTrue($refund->refresh()->isRefund());
        $this->assertSame($original->id, $refund->refund->original_transaction_id);

        $progress = $this->service->compute($budget);
        $this->assertEquals(80.0, $progress['net_spent']);
    }

    public function test_transfers_are_excluded_from_progress(): void
    {
        $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense']);

        $budget = Budget::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
            'amount' => 500,
            'period_type' => Budget::PERIOD_MONTHLY,
            'start_date' => CarbonImmutable::now()->startOfMonth()->toDateString(),
        ]);
        $budget->wallets()->attach($this->wallet);

        // Real spend
        $this->txn(walletId: $this->wallet->id, type: 'expense', amount: 100);

        // Both legs of a wallet-to-wallet transfer must be ignored entirely:
        // one is type=expense (debit) and one is type=income (credit). Neither
        // is real spend nor a refund.
        $other = \App\Models\Wallet::factory()->create(['user_id' => $this->user->id]);
        $transfer = \App\Models\Transfer::factory()->create([
            'user_id' => $this->user->id,
            'from_wallet_id' => $this->wallet->id,
            'to_wallet_id' => $other->id,
        ]);
        $transferDebit = $this->txn(walletId: $this->wallet->id, type: 'expense', amount: 250);
        $transferCredit = $this->txn(walletId: $other->id, type: 'income', amount: 250);
        $transferDebit->transfer_id = $transfer->id;
        $transferDebit->save();
        $transferCredit->transfer_id = $transfer->id;
        $transferCredit->save();

        $progress = $this->service->compute($budget);

        $this->assertEquals(100.0, $progress['gross_spent']);
        $this->assertEquals(0.0, $progress['refunds']);
        $this->assertEquals(100.0, $progress['net_spent']);
    }

    private function attachExpense(Category $category, float $amount): Transaction
    {
        $txn = $this->txn(walletId: $this->wallet->id, type: 'expense', amount: $amount);
        $txn->categories()->attach($category);

        return $txn;
    }

    private function attachRefund(Category $category, float $amount): Transaction
    {
        $txn = $this->txn(walletId: $this->wallet->id, type: 'income', amount: $amount);
        $txn->categories()->attach($category);

        return $txn;
    }

    private function txn(int $walletId, string $type, float $amount, ?string $datetime = null): Transaction
    {
        return Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $walletId,
            'type' => $type,
            'amount' => $amount,
            'datetime' => $datetime ?? CarbonImmutable::now()->toDateTimeString(),
        ]);
    }
}
