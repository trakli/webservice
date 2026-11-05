<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Whilesmart\Entitlements\Contracts\Entitlements;
use Whilesmart\Entitlements\Support\AllowAllEntitlements;

class DebtFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_flows_group_income_spending_transfers_and_pending_debts(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'USD', 'balance' => 1000]);
        $otherWallet = Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'USD']);
        $party = $user->parties()->create(['name' => 'Amanda']);
        $category = $user->categories()->create(['name' => 'Food', 'type' => 'expense']);
        $this->actingAs($user);
        foreach ([
            ['type' => 'income', 'amount' => 400, 'party_id' => $party->id],
            ['type' => 'income', 'amount' => 50],
            ['type' => 'expense', 'amount' => 30, 'categories' => [$category->id]],
            ['type' => 'income', 'intent' => 'loan_received', 'amount' => 80, 'party_id' => $party->id],
            ['type' => 'expense', 'intent' => 'loan_repayment', 'amount' => 10, 'party_id' => $party->id],
        ] as $movement) {
            $this->postJson('/api/v1/transactions', $movement + ['wallet_id' => $wallet->id, 'datetime' => now()->toIso8601String()])->assertCreated();
        }
        $this->postJson('/api/v1/transactions', [
            'party_id' => $party->id, 'wallet_id' => $wallet->id, 'amount' => 100,
            'intent' => 'loan_given', 'type' => 'expense', 'datetime' => now()->toIso8601String(),
        ])->assertCreated();
        $this->postJson('/api/v1/transfers', ['from_wallet_id' => $wallet->id, 'to_wallet_id' => $otherWallet->id, 'amount' => 25])->assertCreated();
        $otherUser = User::factory()->create();
        $foreign = Wallet::factory()->create(['user_id' => $otherUser->id, 'currency' => 'USD']);
        $this->actingAs($otherUser)->postJson('/api/v1/transactions', ['wallet_id' => $foreign->id, 'type' => 'income', 'amount' => 9999, 'datetime' => now()->toIso8601String()])->assertCreated();
        $data = $this->actingAs($user)->getJson('/api/v1/stats/flows')->assertOk()->assertJsonPath('data.partial', false)->json('data');
        $nodes = collect($data['nodes'])->keyBy('id');
        foreach (['source:unknown', 'wallet:'.$wallet->id, 'wallet:'.$otherWallet->id, 'category:'.$category->id, 'person:party:'.$party->id] as $id) {
            $this->assertTrue($nodes->has($id), $id);
        }
        $this->assertFalse($nodes->has('wallet:'.$foreign->id));
        $this->assertFalse($nodes->has('source:party:'.$party->id), 'a party with debts appears once, among people');
        $income = collect($data['edges'])->first(fn ($e) => $e['type'] === 'income' && $e['source'] === 'person:party:'.$party->id);
        $this->assertEquals(400, $income['amount']);
        $edges = collect($data['edges']);
        $this->assertEquals(25, $edges->firstWhere('type', 'transfer')['amount']);
        $this->assertEquals(30, $edges->firstWhere('type', 'spend')['amount']);
        $loans = $edges->where('type', 'loan');
        $this->assertCount(2, $loans);
        $this->assertTrue($loans->first()['pending']);
        $this->assertEquals(80, $loans->firstWhere('target', 'wallet:'.$wallet->id)['amount']);
        $this->assertEquals(110, $loans->firstWhere('source', 'wallet:'.$wallet->id)['amount']);
        $this->getJson('/api/v1/stats/flows?from=2000-01-01&to=2000-01-31')->assertOk()->assertJsonPath('data.edges', []);
        $this->getJson('/api/v1/stats/flows?wallet_ids='.$foreign->id)->assertUnprocessable();
    }

    public function test_flows_convert_currency_merge_categories_and_flag_missing_rates(): void
    {
        $this->app->instance(Entitlements::class, new AllowAllEntitlements());
        $this->mock(ExchangeRateService::class, fn ($mock) => $mock->shouldReceive('getRate')->andReturnUsing(fn ($from, $to) => $from === 'EUR' ? 2.0 : null));
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 1000]);
        $unknownRateWallet = Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'JPY']);
        $this->actingAs($user);
        for ($i = 1; $i <= 10; $i++) {
            $category = $user->categories()->create(['name' => 'Category '.$i, 'type' => 'expense']);
            $this->postJson('/api/v1/transactions', ['wallet_id' => $wallet->id, 'type' => 'expense', 'amount' => $i, 'categories' => [$category->id], 'datetime' => now()->toIso8601String()])->assertCreated();
        }
        $this->postJson('/api/v1/transactions', ['wallet_id' => $unknownRateWallet->id, 'type' => 'income', 'amount' => 100, 'datetime' => now()->toIso8601String()])->assertCreated();
        $data = $this->getJson('/api/v1/stats/flows')->assertOk()->assertJsonPath('data.partial', true)->json('data');
        $spending = collect($data['edges'])->where('type', 'spend');
        $this->assertCount(9, $spending);
        $this->assertEquals(110, $spending->sum('amount'));
        $this->assertEquals(6, $spending->firstWhere('target', 'category:other')['amount']);
    }
}
