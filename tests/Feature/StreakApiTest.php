<?php

namespace Tests\Feature;

use App\Enums\StreakType;
use App\Models\Streak;
use App\Models\User;
use App\Services\StreakService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Whilesmart\ModelConfiguration\Enums\ConfigValueType;

class StreakApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_authentication_is_required(): void
    {
        $this->getJson('/api/v1/streaks')->assertUnauthorized();
        $this->assertDatabaseCount('streaks', 0);
    }

    public function test_first_request_returns_check_ins_with_flat_pagination_and_private_fields_hidden(): void
    {
        $user = User::factory()->create();
        app(StreakService::class)->track(User::factory()->create(), StreakType::TRANSACTION);
        Streak::factory()->create(['owner_id' => $user->id, 'owner_type' => 'another-owner']);

        $response = $this->actingAs($user)->getJson('/api/v1/streaks?limit=1');
        $response->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 2)->assertJsonPath('data.per_page', 1)
            ->assertJsonPath('data.current_page', 1)->assertJsonPath('data.last_page', 2)
            ->assertJsonPath('data.data.0.type', 'check_in')->assertJsonPath('data.data.0.current_length', 1);
        $this->assertSame(['id', 'type', 'period', 'current_length', 'longest_length', 'started_on', 'last_tracked_on', 'is_running'], array_keys($response->json('data.data.0')));
        $this->actingAs($user)->getJson('/api/v1/streaks?limit=1&page=2')
            ->assertOk()->assertJsonPath('data.current_page', 2)->assertJsonPath('data.data.0.period', 'weekly');
    }

    public function test_effective_lengths_expire_without_rewriting_history(): void
    {
        $user = User::factory()->create();
        $monday = CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC');
        foreach ([14, 7, 2, 1] as $daysAgo) {
            app(StreakService::class)->track($user, StreakType::TRANSACTION, $monday->subDays($daysAgo));
        }
        CarbonImmutable::setTestNow($monday);
        $response = $this->actingAs($user)->getJson('/api/v1/streaks')->assertOk();
        $transaction = collect($response->json('data.data'))->where('type', 'transaction')->keyBy('period');
        $this->assertSame(2, $transaction['daily']['current_length']);
        $this->assertSame(2, $transaction['weekly']['current_length']);
        $stored = Streak::where('owner_id', $user->id)->where('type', 'transaction')->get()->keyBy(fn ($row) => $row->period->value);

        CarbonImmutable::setTestNow($monday->addWeek());
        $response = $this->actingAs($user)->getJson('/api/v1/streaks')->assertOk();
        foreach (collect($response->json('data.data'))->where('type', 'transaction') as $row) {
            $this->assertSame(0, $row['current_length']);
            $this->assertFalse($row['is_running']);
            $this->assertSame($stored[$row['period']]->longest_length, $row['longest_length']);
            $this->assertSame($stored[$row['period']]->started_on->toDateString(), $row['started_on']);
            $this->assertSame($stored[$row['period']]->last_tracked_on->toDateString(), $row['last_tracked_on']);
            $this->assertSame($stored[$row['period']]->current_length, $stored[$row['period']]->fresh()->current_length);
        }
    }

    public function test_timezone_boundary_controls_daily_and_weekly_expiry(): void
    {
        $user = User::factory()->create();
        $user->setConfigValue('timezone', 'Pacific/Auckland', ConfigValueType::String);
        app(StreakService::class)->track($user, StreakType::TRANSACTION, CarbonImmutable::parse('2026-09-27 00:00:00', 'Pacific/Auckland'));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-04 09:00:00', 'UTC'));
        $rows = collect($this->actingAs($user)->getJson('/api/v1/streaks')->assertOk()->json('data.data'))
            ->where('type', 'transaction')->keyBy('period');
        $this->assertSame(1, $rows['weekly']['current_length']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC'));
        $rows = collect($this->actingAs($user)->getJson('/api/v1/streaks')->assertOk()->json('data.data'))
            ->where('type', 'transaction')->keyBy('period');
        $this->assertSame(0, $rows['weekly']['current_length']);

        app(StreakService::class)->track($user, StreakType::TRANSACTION, CarbonImmutable::parse('2026-10-04 00:00:00', 'Pacific/Auckland'));
        $this->actingAs($user)->getJson('/api/v1/streaks')->assertOk()->assertJsonPath('data.data.0.current_length', 1);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));
        $this->actingAs($user)->getJson('/api/v1/streaks')->assertOk()->assertJsonPath('data.data.0.current_length', 0);
    }

    public function test_current_check_in_reaches_running_threshold_and_invalid_pagination_is_rejected(): void
    {
        $user = User::factory()->create();
        $today = CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC');
        foreach ([2, 1, 0] as $daysAgo) {
            CarbonImmutable::setTestNow($today->subDays($daysAgo));
            $this->actingAs($user)->getJson('/api/v1/streaks')->assertOk();
        }
        $this->actingAs($user)->getJson('/api/v1/streaks')->assertOk()
            ->assertJsonPath('data.data.0.current_length', 3)->assertJsonPath('data.data.0.is_running', true);
        $this->getJson('/api/v1/streaks?limit=0')->assertUnprocessable();
        $this->getJson('/api/v1/streaks?no_client_id=1')->assertUnprocessable();
    }
}
