<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Mail\InsightsMail;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InsightsService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Whilesmart\ModelConfiguration\Enums\ConfigValueType;

class InsightsServiceTest extends TestCase
{
    use RefreshDatabase;

    private InsightsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $notificationService = new NotificationService(null);
        $this->service = new InsightsService($notificationService);
        Mail::fake();
    }

    public function test_sends_weekly_insights_to_opted_in_users()
    {
        $user = $this->createUserWithTransactions();
        $user->setConfigValue(InsightsService::CONFIG_KEY, 'weekly', ConfigValueType::String);

        $sent = $this->service->sendInsights('weekly');

        $this->assertEquals(1, $sent);
        Mail::assertQueued(InsightsMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function test_sends_monthly_insights_to_opted_in_users()
    {
        $user = $this->createUserWithTransactions();
        $user->setConfigValue(InsightsService::CONFIG_KEY, 'monthly', ConfigValueType::String);

        $sent = $this->service->sendInsights('monthly');

        $this->assertEquals(1, $sent);
        Mail::assertQueued(InsightsMail::class);
    }

    public function test_does_not_send_to_users_with_different_frequency()
    {
        $user = $this->createUserWithTransactions();
        $user->setConfigValue(InsightsService::CONFIG_KEY, 'monthly', ConfigValueType::String);

        $sent = $this->service->sendInsights('weekly');

        $this->assertEquals(0, $sent);
        Mail::assertNothingQueued();
    }

    public function test_does_not_send_to_users_without_preference()
    {
        $this->createUserWithTransactions();

        $sent = $this->service->sendInsights('weekly');

        $this->assertEquals(0, $sent);
        Mail::assertNothingQueued();
    }

    public function test_generates_correct_insights()
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);
        $lastWeek = now()->subWeek();

        Transaction::factory()->create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => TransactionType::INCOME,
            'amount' => 1000,
            'datetime' => $lastWeek,
        ]);

        Transaction::factory()->create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => TransactionType::EXPENSE,
            'amount' => 300,
            'datetime' => $lastWeek,
        ]);

        Transaction::factory()->create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => TransactionType::EXPENSE,
            'amount' => 200,
            'datetime' => $lastWeek,
        ]);

        $insights = $this->service->generateInsights($user, 'weekly');

        $this->assertEquals(1000, $insights['income']);
        $this->assertEquals(500, $insights['expenses']);
        $this->assertEquals(500, $insights['net']);
        $this->assertEquals(50, $insights['savings_rate']);
        $this->assertEquals(3, $insights['transaction_count']);
    }

    public function test_calculates_savings_rate_correctly()
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);
        $lastWeek = now()->subWeek();

        Transaction::factory()->create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => TransactionType::INCOME,
            'amount' => 1000,
            'datetime' => $lastWeek,
        ]);

        Transaction::factory()->create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => TransactionType::EXPENSE,
            'amount' => 750,
            'datetime' => $lastWeek,
        ]);

        $insights = $this->service->generateInsights($user, 'weekly');

        $this->assertEquals(25, $insights['savings_rate']);
    }

    public function test_handles_zero_income()
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);
        $lastWeek = now()->subWeek();

        Transaction::factory()->create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => TransactionType::EXPENSE,
            'amount' => 100,
            'datetime' => $lastWeek,
        ]);

        $insights = $this->service->generateInsights($user, 'weekly');

        $this->assertEquals(0, $insights['income']);
        $this->assertEquals(100, $insights['expenses']);
        $this->assertEquals(0, $insights['savings_rate']);
    }

    public function test_finds_top_expense()
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);
        $lastWeek = now()->subWeek();

        Transaction::factory()->create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => TransactionType::EXPENSE,
            'amount' => 100,
            'description' => 'Small expense',
            'datetime' => $lastWeek,
        ]);

        Transaction::factory()->create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => TransactionType::EXPENSE,
            'amount' => 500,
            'description' => 'Big expense',
            'datetime' => $lastWeek,
        ]);

        $insights = $this->service->generateInsights($user, 'weekly');

        $this->assertEquals('Big expense', $insights['top_expense']['description']);
        $this->assertEquals(500, $insights['top_expense']['amount']);
    }

    public function test_handles_no_transactions()
    {
        $user = User::factory()->create();

        $insights = $this->service->generateInsights($user, 'weekly');

        $this->assertEquals(0, $insights['income']);
        $this->assertEquals(0, $insights['expenses']);
        $this->assertEquals(0, $insights['transaction_count']);
        $this->assertNull($insights['top_expense']);
    }

    public function test_sends_to_multiple_users()
    {
        $user1 = $this->createUserWithTransactions();
        $user1->setConfigValue(InsightsService::CONFIG_KEY, 'weekly', ConfigValueType::String);

        $user2 = $this->createUserWithTransactions();
        $user2->setConfigValue(InsightsService::CONFIG_KEY, 'weekly', ConfigValueType::String);

        $sent = $this->service->sendInsights('weekly');

        $this->assertEquals(2, $sent);
        Mail::assertQueued(InsightsMail::class, 2);
    }

    private function createUserWithTransactions(): User
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);

        Transaction::factory()->create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'datetime' => now()->subDays(3),
        ]);

        return $user;
    }
}
