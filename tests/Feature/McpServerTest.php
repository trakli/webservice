<?php

namespace Tests\Feature;

use App\Mcp\Server\TrakliMcpServer;
use App\Mcp\Tools\ListWalletsTool;
use App\Mcp\Tools\RecordTransactionTool;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_the_endpoint_rejects_unauthenticated_requests(): void
    {
        $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 1,
        ])->assertUnauthorized();
    }

    public function test_a_read_tool_returns_only_the_users_wallets(): void
    {
        $mine = Wallet::factory()->create(['user_id' => $this->user->id, 'name' => 'My Bank']);
        $other = Wallet::factory()->create(['user_id' => User::factory()->create()->id, 'name' => 'Not Mine']);

        TrakliMcpServer::actingAs($this->user)
            ->tool(ListWalletsTool::class)
            ->assertHasNoErrors()
            ->assertSee($mine->name)
            ->assertDontSee($other->name);
    }

    public function test_a_write_tool_records_a_transaction_for_the_user(): void
    {
        $wallet = Wallet::factory()->create(['user_id' => $this->user->id]);

        TrakliMcpServer::actingAs($this->user)
            ->tool(RecordTransactionTool::class, [
                'amount' => 12.5,
                'type' => 'expense',
                'wallet_id' => $wallet->id,
                'description' => 'Coffee',
            ])
            ->assertHasNoErrors();

        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->user->id,
            'wallet_id' => $wallet->id,
            'amount' => 12.5,
            'type' => 'expense',
        ]);
    }

    public function test_a_write_tool_is_denied_when_a_restricting_gate_forbids_it(): void
    {
        Gate::define('transactions.manage', fn () => false);
        $wallet = Wallet::factory()->create(['user_id' => $this->user->id]);

        TrakliMcpServer::actingAs($this->user)
            ->tool(RecordTransactionTool::class, [
                'amount' => 5,
                'type' => 'expense',
                'wallet_id' => $wallet->id,
            ])
            ->assertSee('Permission denied');

        $this->assertDatabaseMissing('transactions', ['wallet_id' => $wallet->id]);
    }
}
