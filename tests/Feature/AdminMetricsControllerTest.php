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
