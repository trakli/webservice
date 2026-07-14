<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\StatsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Whilesmart\ModelConfiguration\Enums\ConfigValueType;

class StatsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze time to Wednesday noon so relative dates (subHours, subDays)
        // land on deterministic days of the week regardless of when tests run
        Carbon::setTestNow(Carbon::create(2026, 2, 25, 12, 0, 0));

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function createTestData(): array
    {
        $wallet1 = Wallet::factory()->create(['user_id' => $this->user->id, 'balance' => 1000]);
        $wallet2 = Wallet::factory()->create(['user_id' => $this->user->id, 'balance' => 2000]);

        $incomeCategory = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'income', 'name' => 'Salary']);
        $expenseCategory1 = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense', 'name' => 'Food']);
        $expenseCategory2 = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense', 'name' => 'Transport']);

        $party1 = Party::factory()->create(['user_id' => $this->user->id, 'name' => 'Employer']);
        $party2 = Party::factory()->create(['user_id' => $this->user->id, 'name' => 'Restaurant']);

        // Income transaction - today (within this week)
        $income1 = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $wallet1->id,
            'party_id' => $party1->id,
            'type' => 'income',
            'amount' => 5000,
            'datetime' => Carbon::now()->subHours(2),
        ]);
        $income1->categories()->attach($incomeCategory->id);

        // Expense transaction - yesterday (within this week)
        $expense1 = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $wallet1->id,
            'party_id' => $party2->id,
            'type' => 'expense',
            'amount' => 500,
            'datetime' => Carbon::now()->subDays(1),
        ]);
        $expense1->categories()->attach($expenseCategory1->id);

        // Expense transaction - earlier this month (may or may not be this week depending on date)
        $expense2 = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $wallet2->id,
            'party_id' => $party2->id,
            'type' => 'expense',
            'amount' => 300,
            'datetime' => Carbon::now()->subDays(10),
        ]);
        $expense2->categories()->attach($expenseCategory2->id);

        // Old transaction - 2 months ago
        $oldExpense = Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $wallet1->id,
            'party_id' => $party2->id,
            'type' => 'expense',
            'amount' => 1000,
            'datetime' => Carbon::now()->subMonths(2),
        ]);
        $oldExpense->categories()->attach($expenseCategory1->id);

        return [
            'wallets' => [$wallet1, $wallet2],
            'categories' => [$incomeCategory, $expenseCategory1, $expenseCategory2],
            'parties' => [$party1, $party2],
            'transactions' => [$income1, $expense1, $expense2, $oldExpense],
        ];
    }

    /** @test */
    public function it_returns_unauthorized_for_unauthenticated_users(): void
    {
        $response = $this->getJson('/api/v1/stats');
        $response->assertStatus(401);
    }

    /** @test */
    public function it_returns_stats_structure_for_authenticated_user(): void
    {
        $this->createTestData();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson('/api/v1/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'overview' => [
                        'total_balance',
                        'net_worth',
                        'total_income',
                        'total_expenses',
                        'net_cash_flow',
                        'avg_monthly_income',
                        'avg_monthly_expenses',
                        'savings_rate',
                    ],
                    'comparisons',
                    'top_categories',
                    'largest_transactions',
                    'charts',
                ],
            ]);
    }

    /** @test */
    public function it_includes_cash_and_holdings_in_total_net_worth(): void
    {
        Wallet::factory()->create(['user_id' => $this->user->id, 'balance' => 1000, 'currency' => 'USD']);
        \Whilesmart\Holdings\Models\Holding::create([
            'owner_type' => \App\Models\User::class,
            'owner_id' => $this->user->id,
            'name' => 'Bitcoin',
            'quantity' => 2, 'unit_price' => 500, 'currency' => 'USD',
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->getJson('/api/v1/stats?preset=all_time&section=position');

        $response->assertStatus(200);
        $position = $response->json('data.position');
        $this->assertEquals(1000, $position['cash_balance']);
        $this->assertEquals(1000, $position['holdings_value']); // 2 * 500
        $this->assertEquals(2000, $position['total_net_worth']);
    }

    /** @test */
    public function it_computes_financial_position_separating_intents(): void
    {
        $wallet = Wallet::factory()->create(['user_id' => $this->user->id, 'balance' => 1000]);

        $cases = [
            ['income', 'regular', 5000],
            ['expense', 'regular', 500],
            ['income', 'loan_received', 3000],
            ['expense', 'loan_repayment', 1000],
            ['expense', 'investment_buy', 2000],
            ['income', 'investment_return', 400],
            ['income', 'gift', 100],
        ];

        foreach ($cases as [$type, $intent, $amount]) {
            Transaction::factory()->create([
                'user_id' => $this->user->id,
                'wallet_id' => $wallet->id,
                'type' => $type,
                'intent' => $intent,
                'amount' => $amount,
                'datetime' => Carbon::now()->subHours(2),
            ]);
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson('/api/v1/stats?preset=all_time&section=position');

        $response->assertStatus(200);

        $position = $response->json('data.position');
        $this->assertEquals(5000, $position['earned_income']); // excludes loan, gift, investment return
        $this->assertEquals(500, $position['discretionary_spend']); // excludes loan repayment, investment buy
        $this->assertEquals(3000, $position['loan_received']);
        $this->assertEquals(1000, $position['loan_repayment']);
        $this->assertEquals(0, $position['debt_owed']);
        $this->assertEquals(0, $position['debt_settled']);
        $this->assertEquals(2000, $position['loans_debt_net']); // 3000 received - 1000 repaid
        $this->assertEquals(2000, $position['investment_principal']);
        $this->assertEquals(400, $position['investment_returns']);
        $this->assertEquals(100, $position['gifts_received']);
        // Net worth excludes investment principal (cash converted to an asset)
        // and loan/debt movement (balance-neutral): 5000 - 500 + 400 + 100.
        $this->assertEquals(5000, $position['net_worth_delta']);
    }

    /** @test */
    public function it_calculates_correct_totals(): void
    {
        $this->createTestData();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson('/api/v1/stats?preset=all_time');

        $response->assertStatus(200);

        $data = $response->json('data.overview');
        $this->assertEquals(5000, $data['total_income']);
        $this->assertEquals(1800, $data['total_expenses']); // 500 + 300 + 1000
        $this->assertEquals(3200, $data['net_cash_flow']); // 5000 - 1800
    }

    /** @test */
    public function it_filters_by_preset_current_week(): void
    {
        $this->createTestData();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson('/api/v1/stats?preset=current_week');

        $response->assertStatus(200);

        $data = $response->json('data.overview');
        // This week's transactions: income 5000 (today-2h), expense 500 (yesterday)
        // The 300 expense (10 days ago) and 1000 (2 months ago) are outside current week
        $this->assertEquals(5000, $data['total_income']);
        $this->assertLessThanOrEqual(800, $data['total_expenses']); // 500 guaranteed, 300 if week spans 10 days
    }

    /** @test */
    public function it_filters_by_preset_current_month(): void
    {
        $this->createTestData();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson('/api/v1/stats?preset=current_month');

        $response->assertStatus(200);

        $data = $response->json('data.overview');
        // This month's transactions: income 5000, expenses 500 + 300 = 800
        // The 1000 expense (2 months ago) is outside current month
        $this->assertEquals(5000, $data['total_income']);
        // 10 days ago should still be in current month unless we're early in the month
        $this->assertGreaterThanOrEqual(500, $data['total_expenses']);
        $this->assertLessThanOrEqual(800, $data['total_expenses']);
    }

    /** @test */
    public function it_filters_by_preset_last_3_months(): void
    {
        $this->createTestData();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson('/api/v1/stats?preset=last_3_months');

        $response->assertStatus(200);

        $data = $response->json('data.overview');
        // All transactions within last 3 months
        $this->assertEquals(5000, $data['total_income']);
        $this->assertEquals(1800, $data['total_expenses']);
    }

    /** @test */
    public function it_filters_by_custom_date_range(): void
    {
        $this->createTestData();

        $startDate = Carbon::now()->subDays(7)->format('Y-m-d');
        $endDate = Carbon::now()->format('Y-m-d');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson("/api/v1/stats?start_date={$startDate}&end_date={$endDate}");

        $response->assertStatus(200);
        $this->assertEquals($startDate, $response->json('data.period_summary.start_date'));
        $this->assertEquals($endDate, $response->json('data.period_summary.end_date'));
    }

    /** @test */
    public function it_filters_stats_by_wallet_id(): void
    {
        $data = $this->createTestData();
        $walletId = $data['wallets'][0]->id;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson("/api/v1/stats?wallet_ids={$walletId}&preset=all_time");

        $response->assertStatus(200);

        // Only wallet1 transactions: income 5000, expenses 500 + 1000 = 1500
        $overview = $response->json('data.overview');
        $this->assertEquals(5000, $overview['total_income']);
        $this->assertEquals(1500, $overview['total_expenses']);
    }

    /** @test */
    public function it_filters_by_multiple_wallet_ids(): void
    {
        $data = $this->createTestData();
        $walletIds = $data['wallets'][0]->id.','.$data['wallets'][1]->id;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson("/api/v1/stats?wallet_ids={$walletIds}&preset=all_time");

        $response->assertStatus(200);

        // Both wallets: all transactions
        $overview = $response->json('data.overview');
        $this->assertEquals(5000, $overview['total_income']);
        $this->assertEquals(1800, $overview['total_expenses']);
    }

    /** @test */
    public function it_rejects_invalid_wallet_ids(): void
    {
        $this->createTestData();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson('/api/v1/stats?wallet_ids=999999');

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['message', 'errors' => ['invalid_wallet_ids']]);
    }

    /** @test */
    public function it_returns_category_breakdown(): void
    {
        $this->createTestData();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson('/api/v1/stats?preset=all_time');

        $response->assertStatus(200);

        $topCategories = $response->json('data.top_categories');
        $this->assertArrayHasKey('income', $topCategories);
        $this->assertArrayHasKey('expenses', $topCategories);

        // Check income category
        $this->assertNotEmpty($topCategories['income']);
        $this->assertEquals('Salary', $topCategories['income'][0]['name']);

        // Check expense categories
        $this->assertNotEmpty($topCategories['expenses']);
    }

    /** @test */
    public function it_returns_party_spending_data(): void
    {
        $this->createTestData();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson('/api/v1/stats?preset=all_time');

        $response->assertStatus(200);

        $partySpending = $response->json('data.charts.party_spending');
        $this->assertNotEmpty($partySpending);
        $this->assertArrayHasKey('name', $partySpending[0]);
        $this->assertArrayHasKey('amount', $partySpending[0]);
        $this->assertArrayHasKey('percentage', $partySpending[0]);
    }

    /** @test */
    public function it_returns_monthly_cash_flow(): void
    {
        $this->createTestData();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson('/api/v1/stats?preset=last_3_months');

        $response->assertStatus(200);

        $cashFlow = $response->json('data.charts.monthly_cash_flow');
        $this->assertIsArray($cashFlow);

        if (! empty($cashFlow)) {
            $this->assertArrayHasKey('period', $cashFlow[0]);
            $this->assertArrayHasKey('income', $cashFlow[0]);
            $this->assertArrayHasKey('expense', $cashFlow[0]);
            $this->assertArrayHasKey('net', $cashFlow[0]);
        }
    }

    /** @test */
    public function it_calculates_savings_rate_correctly(): void
    {
        $this->createTestData();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson('/api/v1/stats?preset=all_time');

        $response->assertStatus(200);

        $overview = $response->json('data.overview');
        // Savings rate = (income - expenses) / income * 100
        // = (5000 - 1800) / 5000 * 100 = 64%
        $expectedRate = ((5000 - 1800) / 5000) * 100;
        $this->assertEquals($expectedRate, $overview['savings_rate']);
    }

    /** @test */
    public function it_returns_empty_stats_for_user_with_no_transactions(): void
    {
        // Don't create any test data

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson('/api/v1/stats');

        $response->assertStatus(200);

        $overview = $response->json('data.overview');
        $this->assertEquals(0, $overview['total_income']);
        $this->assertEquals(0, $overview['total_expenses']);
        $this->assertEquals(0, $overview['net_cash_flow']);
    }

    /** @test */
    public function it_does_not_include_other_users_data(): void
    {
        $this->createTestData();

        // Create another user with transactions
        $otherUser = User::factory()->create();
        $otherWallet = Wallet::factory()->create(['user_id' => $otherUser->id]);
        Transaction::factory()->create([
            'user_id' => $otherUser->id,
            'wallet_id' => $otherWallet->id,
            'type' => 'income',
            'amount' => 99999,
            'datetime' => Carbon::now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson('/api/v1/stats?preset=all_time');

        $response->assertStatus(200);

        // Should not include other user's 99999 income
        $overview = $response->json('data.overview');
        $this->assertEquals(5000, $overview['total_income']);
    }

    private function statsRequest(string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson('/api/v1/stats'.$query);
    }

    /** @test */
    public function it_returns_largest_transactions(): void
    {
        $this->createTestData();

        $response = $this->statsRequest('?preset=all_time');
        $response->assertStatus(200);

        $largest = $response->json('data.largest_transactions');
        $this->assertEquals(5000, $largest['income']['amount']);
        $this->assertEquals(1000, $largest['expense']['amount']);
        $this->assertEquals('Food', $largest['expense']['category']);
    }

    /** @test */
    public function it_returns_activity_metrics(): void
    {
        $this->createTestData();

        $response = $this->statsRequest('?preset=all_time');
        $response->assertStatus(200);

        $activity = $response->json('data.activity');
        $this->assertEquals(4, $activity['transaction_count']);
        $this->assertEquals(2, $activity['unique_parties']);

        // Restaurant is the party of all three expense transactions
        $this->assertEquals('Restaurant', $activity['most_frequent_party']['name']);
        $this->assertEquals(3, $activity['most_frequent_party']['transaction_count']);

        // Food categorises two transactions (one this month, the old one)
        $this->assertEquals('Food', $activity['most_used_category']['name']);
        $this->assertEquals(2, $activity['most_used_category']['transaction_count']);

        $this->assertArrayHasKey('per_day', $activity['frequency']);
        $this->assertArrayHasKey('per_week', $activity['frequency']);
        $this->assertArrayHasKey('per_month', $activity['frequency']);
        $this->assertNotNull($activity['busiest_day']);
    }

    /** @test */
    public function it_calculates_previous_period_comparison(): void
    {
        $wallet = Wallet::factory()->create(['user_id' => $this->user->id]);

        // Current window: 2026-02-16..2026-02-25. Previous window resolves to the
        // immediately preceding equal-length range, which contains 2026-02-10/11.
        Transaction::factory()->create([
            'user_id' => $this->user->id, 'wallet_id' => $wallet->id,
            'type' => 'income', 'amount' => 1000, 'datetime' => Carbon::parse('2026-02-20'),
        ]);
        Transaction::factory()->create([
            'user_id' => $this->user->id, 'wallet_id' => $wallet->id,
            'type' => 'expense', 'amount' => 200, 'datetime' => Carbon::parse('2026-02-21'),
        ]);
        Transaction::factory()->create([
            'user_id' => $this->user->id, 'wallet_id' => $wallet->id,
            'type' => 'income', 'amount' => 500, 'datetime' => Carbon::parse('2026-02-10'),
        ]);
        Transaction::factory()->create([
            'user_id' => $this->user->id, 'wallet_id' => $wallet->id,
            'type' => 'expense', 'amount' => 400, 'datetime' => Carbon::parse('2026-02-11'),
        ]);

        $response = $this->statsRequest('?start_date=2026-02-16&end_date=2026-02-25');
        $response->assertStatus(200);

        $prev = $response->json('data.comparisons.previous_period');
        // income 500 -> 1000 = +100%, expense 400 -> 200 = -50%
        $this->assertEquals(100, $prev['income_change_percent']);
        $this->assertEquals(-50, $prev['expense_change_percent']);
    }

    /** @test */
    public function it_reports_full_growth_when_previous_period_is_empty(): void
    {
        $wallet = Wallet::factory()->create(['user_id' => $this->user->id]);
        Transaction::factory()->create([
            'user_id' => $this->user->id, 'wallet_id' => $wallet->id,
            'type' => 'income', 'amount' => 800, 'datetime' => Carbon::parse('2026-02-20'),
        ]);

        $response = $this->statsRequest('?start_date=2026-02-16&end_date=2026-02-25');
        $response->assertStatus(200);

        $prev = $response->json('data.comparisons.previous_period');
        $this->assertEquals(100, $prev['income_change_percent']);
        $this->assertEquals(0, $prev['expense_change_percent']);
    }

    /** @test */
    public function it_returns_expense_by_wallet(): void
    {
        $data = $this->createTestData();
        [$wallet1, $wallet2] = $data['wallets'];

        $response = $this->statsRequest('?preset=all_time');
        $response->assertStatus(200);

        $byWallet = collect($response->json('data.charts.expense_by_wallet'))
            ->keyBy('wallet_id');

        // wallet1 expenses: 500 + 1000 = 1500; wallet2: 300
        $this->assertEquals(1500, $byWallet[$wallet1->id]['amount']);
        $this->assertEquals(300, $byWallet[$wallet2->id]['amount']);

        // Sorted by amount descending
        $first = $response->json('data.charts.expense_by_wallet.0');
        $this->assertEquals($wallet1->id, $first['wallet_id']);
    }

    /** @test */
    public function it_returns_monthly_cash_flow_values(): void
    {
        $this->createTestData();

        $response = $this->statsRequest('?preset=last_3_months');
        $response->assertStatus(200);

        $byMonth = collect($response->json('data.charts.monthly_cash_flow'))
            ->keyBy('period');

        // February 2026: income 5000, expenses 500 + 300 = 800, net 4200
        $this->assertEquals(5000, $byMonth['2026-02']['income']);
        $this->assertEquals(800, $byMonth['2026-02']['expense']);
        $this->assertEquals(4200, $byMonth['2026-02']['net']);

        // December 2025: the 1000 expense from two months ago
        $this->assertEquals(1000, $byMonth['2025-12']['expense']);
        $this->assertEquals(-1000, $byMonth['2025-12']['net']);
    }

    /** @test */
    public function it_returns_party_chart_values(): void
    {
        $this->createTestData();

        $response = $this->statsRequest('?preset=all_time');
        $response->assertStatus(200);

        $spending = $response->json('data.charts.party_spending');
        $this->assertEquals('Restaurant', $spending[0]['name']);
        $this->assertEquals(1800, $spending[0]['amount']);
        $this->assertEquals(100, $spending[0]['percentage']);

        $income = $response->json('data.charts.party_income');
        $this->assertEquals('Employer', $income[0]['name']);
        $this->assertEquals(5000, $income[0]['amount']);
    }

    /** @test */
    public function it_orders_top_categories_with_percentages(): void
    {
        $this->createTestData();

        $response = $this->statsRequest('?preset=all_time');
        $response->assertStatus(200);

        $expenses = $response->json('data.top_categories.expenses');
        // Food (1500, 2 txns) outranks Transport (300)
        $this->assertEquals('Food', $expenses[0]['name']);
        $this->assertEquals(1500, $expenses[0]['amount']);
        $this->assertEquals(2, $expenses[0]['transaction_count']);
        $this->assertEquals('Transport', $expenses[1]['name']);

        // Percentages over the expense total (1800)
        $this->assertEqualsWithDelta(1500 / 1800 * 100, $expenses[0]['percentage'], 0.01);
    }

    /** @test */
    public function it_returns_category_distribution_summing_to_100(): void
    {
        $this->createTestData();

        $response = $this->statsRequest('?preset=all_time');
        $response->assertStatus(200);

        $dist = $response->json('data.category_distribution');
        // Food and Transport both classify as essential -> 100%
        $this->assertEqualsWithDelta(100, $dist['essential'], 0.01);
        $this->assertEqualsWithDelta(100, array_sum($dist), 0.01);
    }

    /** @test */
    public function it_converts_foreign_currency_to_default_currency(): void
    {
        $this->user->setConfigValue('default-currency', 'USD', ConfigValueType::String);
        $this->user->setConfigValue('manual-exchange-rates', ['EUR-USD' => 1.1], ConfigValueType::Json);

        $eurWallet = Wallet::factory()->create(['user_id' => $this->user->id, 'currency' => 'EUR']);
        Transaction::factory()->create([
            'user_id' => $this->user->id, 'wallet_id' => $eurWallet->id,
            'type' => 'income', 'amount' => 100, 'datetime' => Carbon::now()->subHours(2),
        ]);

        $response = $this->statsRequest('?preset=all_time');
        $response->assertStatus(200);

        // 100 EUR * 1.1 = 110 USD
        $this->assertEqualsWithDelta(110, $response->json('data.overview.total_income'), 0.001);
        $this->assertEquals('USD', $response->json('data.currency'));
    }

    /** @test */
    public function it_converts_party_spending_across_wallet_currencies(): void
    {
        $this->user->setConfigValue('default-currency', 'USD', ConfigValueType::String);
        $this->user->setConfigValue('manual-exchange-rates', ['EUR-USD' => 1.1], ConfigValueType::Json);

        $party = Party::factory()->create(['user_id' => $this->user->id, 'name' => 'Acme']);
        $usdWallet = Wallet::factory()->create(['user_id' => $this->user->id, 'currency' => 'USD']);
        $eurWallet = Wallet::factory()->create(['user_id' => $this->user->id, 'currency' => 'EUR']);

        Transaction::factory()->create([
            'user_id' => $this->user->id, 'wallet_id' => $usdWallet->id, 'party_id' => $party->id,
            'type' => 'expense', 'amount' => 50, 'datetime' => Carbon::now()->subHours(2),
        ]);
        Transaction::factory()->create([
            'user_id' => $this->user->id, 'wallet_id' => $eurWallet->id, 'party_id' => $party->id,
            'type' => 'expense', 'amount' => 100, 'datetime' => Carbon::now()->subHours(3),
        ]);

        $response = $this->statsRequest('?preset=all_time');
        $response->assertStatus(200);

        $spending = collect($response->json('data.charts.party_spending'))
            ->firstWhere('name', 'Acme');

        // Each leg converts from its own wallet currency: 50 USD + (100 EUR * 1.1) = 160 USD
        $this->assertNotNull($spending);
        $this->assertEqualsWithDelta(160, $spending['amount'], 0.001);
    }

    /** @test */
    public function it_flags_stats_as_partial_when_an_exchange_rate_is_missing(): void
    {
        // No manual rate and no API rate available, so the foreign amount can't convert.
        Http::fake(['*' => Http::response([], 200)]);

        $this->user->setConfigValue('default-currency', 'USD', ConfigValueType::String);

        $eurWallet = Wallet::factory()->create(['user_id' => $this->user->id, 'currency' => 'EUR']);
        Transaction::factory()->create([
            'user_id' => $this->user->id, 'wallet_id' => $eurWallet->id,
            'type' => 'income', 'amount' => 100, 'datetime' => Carbon::now()->subHours(2),
        ]);

        $response = $this->statsRequest('?preset=all_time');
        $response->assertStatus(200);

        $this->assertTrue($response->json('data.partial'));
        $this->assertContains('EUR', $response->json('data.unconverted_currencies'));
    }

    /** @test */
    public function it_excludes_transfers_from_stats(): void
    {
        $wallet1 = Wallet::factory()->create(['user_id' => $this->user->id, 'balance' => 1000]);
        $wallet2 = Wallet::factory()->create(['user_id' => $this->user->id]);

        Transaction::factory()->create([
            'user_id' => $this->user->id, 'wallet_id' => $wallet1->id,
            'type' => 'income', 'amount' => 2000, 'datetime' => Carbon::now()->subHours(2),
        ]);

        // Create a transfer through the user-facing endpoint; its legs must not
        // count towards income/expense totals.
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->postJson('/api/v1/transfers', [
                'amount' => 300,
                'from_wallet_id' => $wallet1->id,
                'to_wallet_id' => $wallet2->id,
            ])->assertStatus(201);

        $response = $this->statsRequest('?preset=all_time');
        $response->assertStatus(200);

        $overview = $response->json('data.overview');
        $this->assertEquals(2000, $overview['total_income']);
        $this->assertEquals(0, $overview['total_expenses']);
    }

    /** @test */
    public function it_handles_a_single_transaction(): void
    {
        $wallet = Wallet::factory()->create(['user_id' => $this->user->id]);
        Transaction::factory()->create([
            'user_id' => $this->user->id, 'wallet_id' => $wallet->id,
            'type' => 'income', 'amount' => 1234, 'datetime' => Carbon::now()->subHours(2),
        ]);

        $response = $this->statsRequest('?preset=all_time');
        $response->assertStatus(200);

        $overview = $response->json('data.overview');
        $this->assertEquals(1234, $overview['total_income']);
        $this->assertEquals(0, $overview['total_expenses']);
        $this->assertEquals(100, $overview['savings_rate']);
    }

    /** @test */
    public function it_caches_the_stats_response(): void
    {
        $this->createTestData();

        $this->statsRequest('?preset=all_time')->assertStatus(200);

        $cacheKey = StatsService::generateCacheKey(
            $this->user->id,
            Carbon::parse('2000-01-01')->startOfDay(),
            Carbon::now()->endOfDay(),
            [],
            'month'
        );

        $this->assertTrue(Cache::has($cacheKey));
    }

    /** @test */
    public function it_invalidates_cache_after_a_transaction_write(): void
    {
        $data = $this->createTestData();

        $first = $this->statsRequest('?preset=all_time');
        $this->assertEquals(5000, $first->json('data.overview.total_income'));

        // A new income write should bust the cached payload for this user.
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $data['wallets'][0]->id,
            'type' => 'income',
            'amount' => 1000,
            'datetime' => Carbon::now()->subHours(1),
        ]);

        $second = $this->statsRequest('?preset=all_time');
        $this->assertEquals(6000, $second->json('data.overview.total_income'));
    }

    /** @test */
    public function it_returns_only_the_overview_section_when_requested(): void
    {
        $this->createTestData();

        $response = $this->statsRequest('?preset=all_time&section=overview');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(5000, $data['overview']['total_income']);
        $this->assertEquals(1800, $data['overview']['total_expenses']);
        $this->assertArrayHasKey('period_summary', $data);

        // Heavier sections are not computed for this request.
        $this->assertArrayNotHasKey('activity', $data);
        $this->assertArrayNotHasKey('comparisons', $data);
        $this->assertArrayNotHasKey('top_categories', $data);
        $this->assertArrayNotHasKey('charts', $data);
    }

    /** @test */
    public function it_returns_only_the_categories_section_when_requested(): void
    {
        $this->createTestData();

        $response = $this->statsRequest('?preset=all_time&section=categories');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals('Food', $data['top_categories']['expenses'][0]['name']);
        $this->assertArrayHasKey('category_spending', $data['charts']);
        $this->assertArrayHasKey('income_sources', $data['charts']);

        $this->assertArrayNotHasKey('overview', $data);
        $this->assertArrayNotHasKey('party_spending', $data['charts']);
        $this->assertArrayNotHasKey('monthly_cash_flow', $data['charts']);
    }

    /** @test */
    public function it_returns_only_the_parties_section_when_requested(): void
    {
        $this->createTestData();

        $response = $this->statsRequest('?preset=all_time&section=parties');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals('Restaurant', $data['charts']['party_spending'][0]['name']);
        $this->assertEquals('Employer', $data['charts']['party_income'][0]['name']);
        $this->assertArrayNotHasKey('category_spending', $data['charts']);
        $this->assertArrayNotHasKey('overview', $data);
    }

    /** @test */
    public function it_returns_only_the_cashflow_section_when_requested(): void
    {
        $this->createTestData();

        $response = $this->statsRequest('?preset=all_time&section=cashflow');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(1000, $data['largest_transactions']['expense']['amount']);
        $this->assertArrayHasKey('monthly_cash_flow', $data['charts']);
        $this->assertArrayHasKey('expense_by_wallet', $data['charts']);
        $this->assertArrayNotHasKey('overview', $data);
    }

    /** @test */
    public function it_section_overview_matches_full_payload(): void
    {
        $this->createTestData();

        $full = $this->statsRequest('?preset=all_time')->json('data.overview');
        $section = $this->statsRequest('?preset=all_time&section=overview')->json('data.overview');

        $this->assertEquals($full, $section);
    }

    /** @test */
    public function it_rejects_an_invalid_section(): void
    {
        $this->createTestData();

        $response = $this->statsRequest('?section=bogus');
        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['message', 'errors' => ['invalid_section', 'valid_sections']]);
    }

    /** @test */
    public function it_still_returns_the_full_payload_without_a_section(): void
    {
        $this->createTestData();

        $data = $this->statsRequest('?preset=all_time')->json('data');
        foreach (['overview', 'activity', 'comparisons', 'top_categories', 'largest_transactions', 'charts'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
    }
}
