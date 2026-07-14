<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Whilesmart\ModelConfiguration\Enums\ConfigValueType;

class ExchangeRateApiTest extends TestCase
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
        $this->getJson('/api/v1/exchange-rates?base=USD&target=EUR')->assertStatus(401);
    }

    public function test_returns_rate_map_for_available_pairs(): void
    {
        ExchangeRate::create([
            'base_currency' => 'USD',
            'target_currency' => 'EUR',
            'rate' => 0.9,
            'fetched_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/exchange-rates?base=USD&targets=EUR');

        $response->assertOk();
        $response->assertJsonPath('data.base', 'USD');
        $this->assertEqualsWithDelta(0.9, $response->json('data.rates.EUR'), 0.0001);
        $this->assertSame([], $response->json('data.unavailable'));
    }

    public function test_manual_rate_takes_precedence_over_cached_rate(): void
    {
        // A cached rate exists, but the user's manual override must win.
        ExchangeRate::create([
            'base_currency' => 'USD',
            'target_currency' => 'EUR',
            'rate' => 0.9,
            'fetched_at' => now(),
        ]);
        $this->user->setConfigValue('manual-exchange-rates', ['USD-EUR' => 0.5], ConfigValueType::Json);

        $response = $this->actingAs($this->user)->getJson('/api/v1/exchange-rates?base=USD&target=EUR');

        $response->assertOk();
        $this->assertEqualsWithDelta(0.5, $response->json('data.rates.EUR'), 0.0001);
    }

    public function test_targets_with_no_rate_are_listed_as_unavailable(): void
    {
        // No cached rate and no API rate available for the pair.
        Http::fake(['*' => Http::response([], 200)]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/exchange-rates?base=USD&targets=GBP');

        $response->assertOk();
        $this->assertContains('GBP', $response->json('data.unavailable'));
        $this->assertArrayNotHasKey('GBP', $response->json('data.rates'));
    }

    public function test_base_is_required(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/exchange-rates?targets=EUR')->assertStatus(422);
    }
}
