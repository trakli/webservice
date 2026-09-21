<?php

namespace Tests\Feature;

use App\Enums\StreakPeriod;
use App\Enums\StreakType;
use App\Mail\StreakMilestoneMail;
use App\Models\Streak;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StreakTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private $wallet;

    private CarbonImmutable $start;

    public function test_recording_transactions_on_consecutive_days_builds_a_streak(): void
    {
        foreach ([2, 1, 0] as $daysAgo) {
            CarbonImmutable::setTestNow($this->start->subDays($daysAgo));
            $this->recordTransaction();
        }
        CarbonImmutable::setTestNow();

        $streak = $this->streak(StreakType::TRANSACTION, StreakPeriod::DAILY);

        $this->assertSame(3, $streak->current_length);
        $this->assertSame(3, $streak->longest_length);
    }

    public function test_two_transactions_on_one_day_count_once(): void
    {
        $this->recordTransaction();
        $this->recordTransaction();

        $this->assertSame(1, $this->streak(StreakType::TRANSACTION, StreakPeriod::DAILY)->current_length);
    }

    public function test_a_missed_day_restarts_the_streak_but_keeps_the_best_run(): void
    {
        foreach ([5, 4, 3] as $daysAgo) {
            CarbonImmutable::setTestNow($this->start->subDays($daysAgo));
            $this->recordTransaction();
        }
        CarbonImmutable::setTestNow();
        $this->recordTransaction();

        $streak = $this->streak(StreakType::TRANSACTION, StreakPeriod::DAILY);

        $this->assertSame(1, $streak->current_length);
        $this->assertSame(3, $streak->longest_length);
    }

    public function test_one_action_advances_the_daily_and_the_weekly_streak(): void
    {
        $this->recordTransaction();

        $this->assertSame(1, $this->streak(StreakType::TRANSACTION, StreakPeriod::DAILY)->current_length);
        $this->assertSame(1, $this->streak(StreakType::TRANSACTION, StreakPeriod::WEEKLY)->current_length);
    }

    public function test_reaching_a_streak_across_weeks_counts_weeks_not_days(): void
    {
        foreach ([14, 7, 0] as $daysAgo) {
            CarbonImmutable::setTestNow($this->start->startOfWeek()->addDay()->subDays($daysAgo));
            $this->recordTransaction();
        }
        CarbonImmutable::setTestNow();

        $this->assertSame(3, $this->streak(StreakType::TRANSACTION, StreakPeriod::WEEKLY)->current_length);
    }

    public function test_the_owner_is_polymorphic_rather_than_a_user_column(): void
    {
        $this->recordTransaction();

        $streak = $this->streak(StreakType::TRANSACTION, StreakPeriod::DAILY);

        $this->assertSame(User::class, $streak->owner_type);
        $this->assertSame($this->user->id, $streak->owner_id);
        $this->assertTrue($streak->owner->is($this->user));
    }

    public function test_reading_the_api_builds_a_check_in_streak(): void
    {
        foreach ([2, 1, 0] as $daysAgo) {
            CarbonImmutable::setTestNow($this->start->subDays($daysAgo));
            $this->actingAs($this->user)->getJson('/api/v1/user')->assertOk();
        }
        CarbonImmutable::setTestNow();

        $this->assertSame(3, $this->streak(StreakType::CHECK_IN, StreakPeriod::DAILY)->current_length);
    }

    public function test_no_email_before_the_streak_reaches_the_threshold(): void
    {
        Mail::fake();

        foreach ([1, 0] as $daysAgo) {
            CarbonImmutable::setTestNow($this->start->subDays($daysAgo));
            $this->recordTransaction();
        }
        CarbonImmutable::setTestNow();

        Mail::assertNothingQueued();
    }

    public function test_the_third_day_in_a_row_sends_one_email(): void
    {
        Mail::fake();

        foreach ([2, 1, 0] as $daysAgo) {
            CarbonImmutable::setTestNow($this->start->subDays($daysAgo));
            $this->recordTransaction();
        }
        CarbonImmutable::setTestNow();

        Mail::assertQueued(StreakMilestoneMail::class, 1);
    }

    public function test_days_between_milestones_are_silent(): void
    {
        Mail::fake();

        foreach (range(5, 0) as $daysAgo) {
            CarbonImmutable::setTestNow($this->start->subDays($daysAgo));
            $this->recordTransaction();
        }
        CarbonImmutable::setTestNow();

        Mail::assertQueued(StreakMilestoneMail::class, 1);
    }

    private function recordTransaction(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 10,
            'wallet_id' => $this->wallet->id,
            'datetime' => CarbonImmutable::now()->toIso8601String(),
        ])->assertCreated();
    }

    private function streak(StreakType $type, StreakPeriod $period): Streak
    {
        return Streak::where('owner_type', User::class)
            ->where('owner_id', $this->user->id)
            ->where('type', $type->value)
            ->where('period', $period->value)
            ->firstOrFail();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = CarbonImmutable::now();
        $this->user = User::factory()->create();
        $this->wallet = $this->user->wallets()->create(['name' => 'Wallet', 'balance' => 1000]);
    }
}
