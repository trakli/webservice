<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Whilesmart\AgentMetrics\Facades\TokenMeter;
use Whilesmart\Roles\Models\Role;

class AdminMetricsControllerTest extends TestCase
{
    use RefreshDatabase;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $this->admin->assignRole('admin');
    }

    public function test_admin_metrics_narrows_visitor_figures_to_the_selected_client(): void
    {
        config()->set('engagement.clients.website.site_key', 'website-key');
        config()->set('engagement.clients.website.allowed_origins', ['https://www.trakli.test']);
        config()->set('engagement.clients.dashboard.site_key', 'dashboard-key');
        config()->set('engagement.clients.dashboard.allowed_origins', ['https://app.trakli.test']);

        foreach ([
            ['origin' => 'https://www.trakli.test', 'site_key' => 'website-key', 'visitor' => 'w1'],
            ['origin' => 'https://app.trakli.test', 'site_key' => 'dashboard-key', 'visitor' => 'd1'],
        ] as $visit) {
            $this->withHeaders([
                'Origin' => $visit['origin'],
                'X-Engagement-Site-Key' => $visit['site_key'],
            ])->postJson('/api/v1/engagement/events', [
                'events' => [[
                    'name' => 'page.view',
                    'visitor_id' => $visit['visitor'],
                    'session_id' => $visit['visitor'],
                ]],
            ])->assertAccepted();
        }

        $all = $this->actingAs($this->admin)->getJson('/api/v1/admin/metrics?days=30');
        $scoped = $this->actingAs($this->admin)->getJson('/api/v1/admin/metrics?days=30&client=website');

        $visitors = fn ($response) => collect(
            collect($response->json('data.groups'))->firstWhere('key', 'visitors')['metrics']
        )->firstWhere('key', 'unique_visitors')['value'];

        $this->assertSame(2, $visitors($all));
        $this->assertSame(1, $visitors($scoped), 'The client parameter must narrow visitor figures.');
        $scoped->assertJsonPath('data.selected_client', 'website');
    }

    public function test_admin_metrics_rejects_a_client_it_does_not_know(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/metrics?client=typo')
            ->assertStatus(422);
    }

    public function test_admin_metrics_says_which_groups_a_client_filter_narrows(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/metrics?days=7');

        $groups = collect($response->json('data.groups'))->keyBy('key');

        $this->assertTrue($groups['visitors']['client_scoped']);
        $this->assertFalse($groups['users']['client_scoped']);
    }

    public function test_admin_metrics_reports_real_counts(): void
    {
        $wallet = $this->admin->wallets()->create(['name' => 'W', 'balance' => 0]);
        foreach (range(1, 3) as $i) {
            $this->admin->transactions()->create([
                'type' => 'expense',
                'amount' => 10,
                'wallet_id' => $wallet->id,
                'datetime' => now()->subDays(2),
            ]);
        }
        TokenMeter::record($this->admin, 'gemini', 'gemini-flash-latest', [
            'prompt_tokens' => 120,
            'completion_tokens' => 30,
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/metrics?days=30');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'period' => ['start', 'end', 'granularity'],
                    'groups' => [['key', 'label', 'metrics' => [['key', 'label', 'type']]]],
                ],
            ]);

        $groups = collect($response->json('data.groups'))->keyBy('key');
        $users = collect($groups['users']['metrics'])->keyBy('key');
        $transactions = collect($groups['transactions']['metrics'])->keyBy('key');

        $expectedTx = Transaction::whereBetween('datetime', [now()->subDays(29)->startOfDay(), now()->endOfDay()])->count();

        $this->assertSame(User::count(), $users['total_users']['value']);
        $this->assertSame($expectedTx, $transactions['total_transactions']['value']);
        $this->assertGreaterThanOrEqual(3, $transactions['total_transactions']['value']);
        $this->assertNotEmpty($transactions['transactions_series']['series']);

        $engagement = collect($groups['engagement']['metrics'])->keyBy('key');
        $this->assertArrayHasKey('avg_transactions_per_user', $engagement->all());

        $features = $engagement['most_used_features'];
        $this->assertSame('ranking', $features['type']);
        $this->assertNotEmpty($features['rows']);
        $this->assertSame('Transactions', $features['rows'][0]['label']);

        $usage = collect($groups['agent_usage']['metrics'])->keyBy('key');
        $this->assertSame(150, $usage['ai_tokens_used']['value']);
        $this->assertSame(150, collect($usage['ai_tokens_series']['series'])->sum('value'));
        $this->assertSame(150, $usage['ai_tokens_by_user']['rows'][0]['value']);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/admin/metrics');

        $response->assertStatus(403);
    }
}
