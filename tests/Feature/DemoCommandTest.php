<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ConfigurationKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\CreatesApplication;

class DemoCommandTest extends TestCase
{
    use CreatesApplication;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_newcomer_credentials_sign_in_to_an_unfinished_account(): void
    {
        $credentials = $this->createDemo('onboarding');
        $response = $this->postJson('/api/v1/login', [
            'email' => $credentials['username'],
            'password' => $credentials['password'],
        ])->assertOk();
        $user = User::findOrFail($response->json('data.user.id'));

        $this->assertFalse((bool) $user->getConfigValue(ConfigurationKeys::ONBOARDING_COMPLETE, false));
        $this->assertSame(1, $user->wallets()->count());
        $this->assertSame(0, $user->transactions()->count());
    }

    public function test_active_accounts_are_fresh_and_preserve_existing_accounts(): void
    {
        $existing = User::factory()->create();
        $before = $existing->fresh()->getAttributes();
        $first = $this->createDemo('active-month');
        $second = $this->createDemo('active-month');

        $this->assertNotSame($first['username'], $second['username']);
        $this->assertNotSame($first['password'], $second['password']);
        $this->assertSame($before, $existing->fresh()->getAttributes());

        foreach ([$first, $second] as $credentials) {
            $response = $this->postJson('/api/v1/login', [
                'email' => $credentials['username'],
                'password' => $credentials['password'],
            ])->assertOk();
            $user = User::findOrFail($response->json('data.user.id'));

            $this->assertTrue((bool) $user->getConfigValue(ConfigurationKeys::ONBOARDING_COMPLETE));
            $this->assertSame(3, $user->wallets()->count());
            $this->assertGreaterThan(30, $user->transactions()->count());
            $this->assertSame(1, $user->transfers()->count());
            $this->assertSame(['EUR', 'USD'], $user->wallets()->distinct()->orderBy('currency')->pluck('currency')->all());
            $this->assertEqualsWithDelta(
                1850 + $user->transactions()->whereHas('wallet', fn ($query) => $query->where('name', 'Main Checking'))
                    ->get()->sum(fn ($transaction) => $transaction->type === 'expense' ? -$transaction->amount : $transaction->amount),
                $user->wallets()->where('name', 'Main Checking')->value('balance'),
                0.001,
            );
        }
    }

    public function test_shared_space_owner_sees_a_seeded_shared_space(): void
    {
        $credentials = $this->createDemo('shared-space');
        $token = $this->postJson('/api/v1/login', [
            'email' => $credentials['username'],
            'password' => $credentials['password'],
        ])->assertOk()->json('data.token');
        $this->withToken($token);

        $this->getJson('/api/v1/workspaces')->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.slug', $credentials['workspace'])
            ->assertJsonMissingPath('data.data.0.kind')
            ->assertJsonPath('data.data.0.role', 'owner')
            ->assertJsonPath('data.data.0.member_count', 2);
        $base = '/api/v1/workspaces/' . $credentials['workspace'];
        $this->getJson($base . '/finance')->assertOk()->assertJsonCount(1, 'data.wallets')
            ->assertJsonCount(2, 'data.budgets')->assertJsonCount(4, 'data.categories')->assertJsonCount(1, 'data.groups');
        $this->getJson($base . '/transactions')->assertOk()->assertJsonPath('data.total', 11);
        $this->getJson($base . '/invitations')->assertOk()->assertJsonCount(1, 'data');
        $this->assertMatchesRegularExpression('#^/invitations/[A-Za-z0-9]+$#', $credentials['invitation']);
        $this->getJson('/api/v1/workspaces/invitations/' . basename($credentials['invitation']))->assertOk();
    }

    public function test_shared_space_stays_an_empty_draft_where_changes_are_not_allowed(): void
    {
        $entitlements = \Mockery::mock(\Whilesmart\Entitlements\Contracts\Entitlements::class)->shouldIgnoreMissing();
        $entitlements->shouldReceive('allows')->andReturnUsing(fn ($owner) => ! $owner instanceof \App\Models\Workspace);
        $this->app->instance(\Whilesmart\Entitlements\Contracts\Entitlements::class, $entitlements);

        $credentials = $this->createDemo('shared-space');
        $this->assertArrayNotHasKey('invitation', $credentials);
        $workspace = \App\Models\Workspace::where('slug', $credentials['workspace'])->firstOrFail();
        $this->assertSame(1, $workspace->members()->distinct()->count('users.id'));
        $this->assertSame(0, \App\Models\Wallet::where('owner_type', \App\Models\Workspace::class)->where('owner_id', $workspace->id)->count());
        $this->assertSame(0, $workspace->budgets()->count());
    }

    public function test_showcase_account_is_ready_for_screenshots_through_the_api(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-27 12:00:00'));
        $credentials = $this->createDemo('showcase');
        $login = $this->postJson('/api/v1/login', [
            'email' => $credentials['username'], 'password' => $credentials['password'],
        ])->assertOk()->assertJsonPath('data.user.first_name', 'Maya')
            ->assertJsonPath('data.user.last_name', 'Fischer');
        $this->withToken($login->json('data.token'));

        $this->getJson('/api/v1/wallets')->assertOk()->assertJsonPath('data.total', 5);
        $transactions = $this->getJson('/api/v1/transactions?limit=500')->assertOk();
        $this->assertGreaterThanOrEqual(150, $transactions->json('data.total'));
        $dates = collect($transactions->json('data.data'))->pluck('datetime');
        $this->assertTrue($dates->every(fn ($date) => \Carbon\Carbon::parse($date)->lte(now())));
        $this->assertTrue($dates->every(fn ($date) => \Carbon\Carbon::parse($date)->gte(today()->subMonthsNoOverflow(6))));
        $this->assertGreaterThanOrEqual(6, $dates->map(fn ($date) => substr($date, 0, 7))->unique()->count());
        $flows = $this->getJson('/api/v1/stats/flows?from=2026-05-27&to=2026-11-27')->assertOk();
        $this->assertNotEmpty($flows->json('data.nodes'));
        $this->assertNotEmpty($flows->json('data.edges'));
        $flows->assertJsonPath('data.partial', false);

        $budgets = $this->getJson('/api/v1/budgets?active=true')->assertOk()->assertJsonCount(4, 'data.data');
        $byName = collect($budgets->json('data.data'))->keyBy('name');
        foreach (['Food & Dining' => 80, 'Transportation' => 55, 'Shopping' => 120, 'Subscriptions' => 100] as $name => $percent) {
            $this->assertEqualsWithDelta($percent, $byName[$name]['progress']['percent_used'], 0.1);
            $this->assertSame('2026-11-01', $byName[$name]['progress']['period_start']);
        }
        $parties = $this->getJson('/api/v1/parties?limit=100')->assertOk()->json('data.data');
        $amara = collect($parties)->firstWhere('name', 'Amara Okafor');
        $this->assertNotNull($amara);
        $this->getJson('/api/v1/parties/' . $amara['id'])->assertOk()
            ->assertJsonPath('data.balances.0.currency', 'EUR')
            ->assertJsonPath('data.balances.0.they_owe_me', 150);
        $sessions = $this->getJson('/api/v1/import/sessions')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'ready')->assertJsonCount(12, 'data.0.suggestions')
            ->assertJsonPath('data.0.metadata.duplicates_found', 1);
        $this->assertNotNull($sessions->json('data.0.suggestions.0.duplicate'));
        $this->assertNotNull($sessions->json('data.0.suggestions.0.party_id'));
        $this->assertNotNull($sessions->json('data.0.suggestions.0.category_id'));
        $this->getJson('/api/v1/workspaces')->assertOk()
            ->assertJsonPath('data.data.0.slug', $credentials['workspace'])
            ->assertJsonPath('data.data.0.member_count', 2);
        $this->getJson('/api/v1/workspaces/' . $credentials['workspace'] . '/invitations')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->assertArrayHasKey('invitation', $credentials);
        $this->getJson('/api/v1/reminders')->assertOk()->assertJsonPath('data.total', 3);
    }

    public function test_showcase_is_deterministic_and_has_budget_progress_on_the_first_day(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-01 00:00:00'));
        $snapshots = [];
        for ($i = 0; $i < 2; $i++) {
            $this->app['auth']->forgetGuards();
            $this->app['auth']->shouldUse('web');
            $this->withHeader('Authorization', '');
            $credentials = $this->createDemo('showcase');
            $token = $this->postJson('/api/v1/login', [
                'email' => $credentials['username'], 'password' => $credentials['password'],
            ])->assertOk()->json('data.token');
            $this->withToken($token);
            $transactions = $this->getJson('/api/v1/transactions?limit=500')->assertOk()->json('data.data');
            $snapshots[] = collect($transactions)->map(fn ($row) => \Illuminate\Support\Arr::only(
                $row,
                ['amount', 'type', 'description', 'datetime', 'intent']
            ))->all();
            $budgets = $this->getJson('/api/v1/budgets')->assertOk()->assertJsonCount(4, 'data.data');
            foreach ($budgets->json('data.data') as $budget) {
                $this->assertGreaterThan(0, $budget['progress']['net_spent']);
            }
        }
        $this->assertSame($snapshots[0], $snapshots[1]);
    }

    public function test_unknown_scenarios_do_not_create_accounts(): void
    {
        $before = User::count();
        $this->artisan('demo', ['scenario' => 'unknown'])->assertFailed();
        $this->assertSame($before, User::count());
    }

    public function test_screenshot_accounts_are_refused_outside_local_and_testing(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $before = User::count();
            $this->app['env'] = $environment;
            try {
                $this->artisan('demo', ['scenario' => 'active-month'])->assertFailed();
                $this->assertSame($before, User::count());
            } finally {
                $this->app['env'] = 'testing';
            }
        }
    }

    private function createDemo(string $scenario): array
    {
        $this->assertSame(0, Artisan::call('demo', ['scenario' => $scenario]));
        $lines = explode("\n", trim(Artisan::output()));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
