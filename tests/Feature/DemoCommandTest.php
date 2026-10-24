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
