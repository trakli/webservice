<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
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
            ->assertJsonStructure(['message', 'invalid_wallet_ids']);
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
}
