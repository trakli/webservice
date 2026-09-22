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
use Whilesmart\ModelConfiguration\Enums\ConfigValueType;

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

    public function test_a_streak_day_follows_the_users_own_timezone(): void
    {
        $this->user->setConfigValue('timezone', 'Pacific/Auckland', ConfigValueType::String);

        // 20:00 UTC is already the next day in Auckland, so these two land on
        // different local days and the streak reaches two rather than one.
        CarbonImmutable::setTestNow($this->start->startOfDay()->setTime(6, 0));
        $this->recordTransaction();
        CarbonImmutable::setTestNow($this->start->startOfDay()->setTime(20, 0));
        $this->recordTransaction();
        CarbonImmutable::setTestNow();

        $this->assertSame(2, $this->streak(StreakType::TRANSACTION, StreakPeriod::DAILY)->current_length);
    }

    public function test_a_user_who_turned_email_off_is_not_mailed(): void
    {
        Mail::fake();
        $this->user->setConfigValue('notifications-email', false, ConfigValueType::Boolean);

        foreach ([2, 1, 0] as $daysAgo) {
            CarbonImmutable::setTestNow($this->start->subDays($daysAgo));
            $this->recordTransaction();
        }
        CarbonImmutable::setTestNow();

        Mail::assertNothingQueued();
    }

    public function test_a_check_in_is_not_swallowed_when_the_local_day_rolls_over(): void
    {
        $this->user->setConfigValue('timezone', 'Pacific/Auckland', ConfigValueType::String);

        // Both fall on the same UTC day, but 05:00 and 20:00 UTC are different
        // days in Auckland, so this is two local check-in days, not one.
        CarbonImmutable::setTestNow($this->start->startOfDay()->setTime(5, 0));
        $this->actingAs($this->user)->getJson('/api/v1/user')->assertOk();
        CarbonImmutable::setTestNow($this->start->startOfDay()->setTime(20, 0));
        $this->actingAs($this->user)->getJson('/api/v1/user')->assertOk();
        CarbonImmutable::setTestNow();

        $this->assertSame(2, $this->streak(StreakType::CHECK_IN, StreakPeriod::DAILY)->current_length);
    }

    public function test_one_action_never_sends_two_milestone_emails(): void
    {
        // Three consecutive weeks, with the last seven days unbroken, so the
        // final action carries the weekly count and the daily count over a
        // milestone at the same moment.
        $monday = $this->start->startOfWeek();

        foreach (array_merge([-8], range(-6, -1)) as $offset) {
            CarbonImmutable::setTestNow($monday->addDays($offset));
            $this->recordTransaction();
        }

        Mail::fake();
        CarbonImmutable::setTestNow($monday);
        $this->recordTransaction();
        CarbonImmutable::setTestNow();

        $daily = $this->streak(StreakType::TRANSACTION, StreakPeriod::DAILY);
        $weekly = $this->streak(StreakType::TRANSACTION, StreakPeriod::WEEKLY);

        $this->assertSame(7, $daily->current_length);
        $this->assertSame(3, $weekly->current_length);
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
