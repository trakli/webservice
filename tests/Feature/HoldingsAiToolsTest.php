<?php

namespace Tests\Feature;

use App\Ai\Tools\Read\GetStatsTool;
use App\Ai\Tools\Read\ListHoldingsTool;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Whilesmart\Agents\ValueObjects\ToolContext;
use Whilesmart\Holdings\Models\Holding;

class HoldingsAiToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_holdings_tool_returns_only_the_users_holdings()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Holding::create(['owner_type' => User::class, 'owner_id' => $user->id, 'name' => 'Bitcoin', 'quantity' => 0.5, 'unit_price' => 60000, 'currency' => 'USD']);
        Holding::create(['owner_type' => User::class, 'owner_id' => $other->id, 'name' => 'Theirs', 'quantity' => 1, 'unit_price' => 1, 'currency' => 'USD']);

        $result = app(ListHoldingsTool::class)->handle([], ToolContext::forUser($user, 'en'));

        $this->assertCount(1, $result['holdings']);
        $this->assertEquals('Bitcoin', $result['holdings'][0]['name']);
        $this->assertEquals(30000, $result['holdings'][0]['value']);
    }

    public function test_get_stats_tool_exposes_the_position_section()
    {
        $user = User::factory()->create();
        Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1000, 'currency' => 'USD']);
        Holding::create(['owner_type' => User::class, 'owner_id' => $user->id, 'name' => 'Bitcoin', 'quantity' => 2, 'unit_price' => 500, 'currency' => 'USD']);

        $result = app(GetStatsTool::class)->handle(['section' => 'position'], ToolContext::forUser($user, 'en'));

        $this->assertArrayHasKey('position', $result);
        $this->assertEquals(1000, $result['position']['cash_balance']);
        $this->assertEquals(1000, $result['position']['holdings_value']); // 2 * 500
        $this->assertEquals(2000, $result['position']['total_net_worth']);
    }
}
