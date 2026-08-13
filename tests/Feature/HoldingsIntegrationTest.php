<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Whilesmart\Holdings\Models\Holding;

class HoldingsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function ownerPayload(array $extra = []): array
    {
        return array_merge([
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
            'name' => 'Bitcoin',
            'quantity' => 0.5,
            'currency' => 'USD',
            'unit_price' => 60000,
            'price_source' => 'manual',
        ], $extra);
    }

    public function test_user_creates_a_holding_via_the_package_route()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/holdings', $this->ownerPayload());

        $response->assertStatus(201);
        $this->assertEquals(30000, $response->json('data.value')); // 0.5 * 60000
        $this->assertDatabaseHas('holdings', [
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
            'name' => 'Bitcoin',
        ]);
    }

    public function test_index_is_scoped_to_the_authenticated_user()
    {
        Holding::create($this->ownerPayload(['name' => 'Mine']));
        $other = User::factory()->create();
        Holding::create(['owner_type' => User::class, 'owner_id' => $other->id, 'name' => 'Theirs', 'quantity' => 1, 'unit_price' => 1, 'currency' => 'USD']);

        $response = $this->actingAs($this->user)->getJson('/api/v1/holdings');

        $response->assertStatus(200);
        $names = collect($response->json('data.data'))->pluck('name')->all();
        $this->assertContains('Mine', $names);
        $this->assertNotContains('Theirs', $names);
    }

    public function test_creating_for_another_owner_is_forbidden()
    {
        $other = User::factory()->create();

        $this->actingAs($this->user)
            ->postJson('/api/v1/holdings', $this->ownerPayload(['owner_id' => $other->id]))
            ->assertStatus(403);
    }

    public function test_creating_an_auto_holding_prices_it_from_coingecko_immediately()
    {
        Http::fake([
            'api.coingecko.com/api/v3/simple/price*' => Http::response(['bitcoin' => ['usd' => 70000]], 200),
        ]);

        $payload = $this->ownerPayload([
            'price_source' => 'auto', 'provider' => 'coingecko', 'external_ref' => 'bitcoin',
        ]);
        unset($payload['unit_price']);

        $response = $this->actingAs($this->user)->postJson('/api/v1/holdings', $payload);

        $response->assertStatus(201);
        $this->assertEquals(70000, $response->json('data.unit_price'));
        $this->assertNotNull($response->json('data.last_priced_at'));
    }

    public function test_reprice_updates_auto_holdings_via_coingecko()
    {
        Http::fake([
            'api.coingecko.com/api/v3/simple/price*' => Http::response(['bitcoin' => ['usd' => 70000]], 200),
        ]);

        $holding = Holding::create($this->ownerPayload([
            'name' => 'Bitcoin', 'unit_price' => 1, 'price_source' => 'auto', 'provider' => 'coingecko', 'external_ref' => 'bitcoin',
        ]));

        $this->actingAs($this->user)->postJson('/api/v1/holdings/reprice')->assertStatus(200);

        $this->assertEquals(70000, $holding->fresh()->unit_price);
    }

    public function test_asset_price_search_proxies_coingecko()
    {
        Http::fake([
            'api.coingecko.com/api/v3/search*' => Http::response([
                'coins' => [['id' => 'bitcoin', 'name' => 'Bitcoin', 'symbol' => 'btc']],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/asset-prices/search?q=bitcoin');

        $response->assertStatus(200);
        $this->assertEquals('bitcoin', $response->json('data.0.id'));
        $this->assertEquals('BTC', $response->json('data.0.symbol'));
    }
}
