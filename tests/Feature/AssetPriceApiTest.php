<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssetPriceApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/asset-prices?ids=bitcoin')->assertStatus(401);
    }

    public function test_returns_prices_for_requested_ids(): void
    {
        Http::fake([
            'api.coingecko.com/api/v3/simple/price*' => Http::response([
                'bitcoin' => ['usd' => 65000],
                'ethereum' => ['usd' => 3200],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/asset-prices?ids=bitcoin,ethereum&vs_currency=usd');

        $response->assertOk();
        $response->assertJsonPath('data.vs_currency', 'usd');
        $this->assertEqualsWithDelta(65000, $response->json('data.prices.bitcoin'), 0.001);
        $this->assertEqualsWithDelta(3200, $response->json('data.prices.ethereum'), 0.001);
    }

    public function test_ids_are_required(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/asset-prices')->assertStatus(422);
    }

    public function test_coin_search_returns_matches(): void
    {
        Http::fake([
            'api.coingecko.com/api/v3/search*' => Http::response([
                'coins' => [
                    ['id' => 'bitcoin', 'name' => 'Bitcoin', 'symbol' => 'btc'],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/asset-prices/search?q=bitcoin');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', 'bitcoin');
        $response->assertJsonPath('data.0.symbol', 'BTC');
    }
}
