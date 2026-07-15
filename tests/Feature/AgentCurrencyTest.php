<?php

namespace Tests\Feature;

use App\Ai\Harnesses\TrakliHarness;
use App\Ai\Tools\Read\ConvertCurrencyTool;
use App\Ai\Tools\Read\GetUserDefaultsTool;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;
use Whilesmart\Agents\ValueObjects\ToolContext;
use Whilesmart\ModelConfiguration\Enums\ConfigValueType;

class AgentCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function contextFor(User $user): ToolContext
    {
        return ToolContext::forUser($user, 'en');
    }

    private function promptFor(?User $user): string
    {
        return app(TrakliHarness::class)->systemPrompt(
            $user ? $this->contextFor($user) : null
        );
    }

    public function test_prompt_states_the_users_configured_currency(): void
    {
        $user = User::factory()->create();
        $user->setConfigValue('default-currency', 'XAF', ConfigValueType::String);
        Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'XAF']);

        $prompt = $this->promptFor($user);

        $this->assertStringContainsString('currency is XAF', $prompt);
        $this->assertStringContainsString('Report every amount in XAF', $prompt);
    }

    public function test_prompt_warns_when_wallets_hold_other_currencies(): void
    {
        $user = User::factory()->create();
        $user->setConfigValue('default-currency', 'XAF', ConfigValueType::String);
        Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'XAF']);
        Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);

        $prompt = $this->promptFor($user);

        $this->assertStringContainsString('EUR', $prompt);
        $this->assertStringContainsString('convert_currency', $prompt);
    }

    public function test_prompt_infers_currency_from_a_lone_wallet_when_unset(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'GBP']);

        $this->assertStringContainsString('currency is GBP', $this->promptFor($user));
    }

    public function test_prompt_asks_rather_than_assuming_when_currency_is_unset_and_wallets_differ(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'GBP']);
        Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'NGN']);

        $prompt = $this->promptFor($user);

        $this->assertStringContainsString('has NOT set a default currency', $prompt);
        $this->assertStringContainsString('Never assume dollars', $prompt);
    }

    public function test_prompt_never_assumes_dollars_without_a_user(): void
    {
        $this->assertStringContainsString('Never assume dollars', $this->promptFor(null));
    }

    public function test_defaults_tool_reports_the_configured_currency_and_wallet(): void
    {
        $user = User::factory()->create();
        $user->setConfigValue('default-currency', 'XAF', ConfigValueType::String);
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'XAF']);
        $user->setConfigValue('default-wallet', (string) $wallet->id, ConfigValueType::String);

        $result = app(GetUserDefaultsTool::class)->handle([], $this->contextFor($user));

        $this->assertSame('XAF', $result['currency']);
        $this->assertTrue($result['currency_is_set']);
        $this->assertSame($wallet->id, $result['default_wallet']['id']);
    }

    public function test_defaults_tool_resolves_a_wallet_stored_as_a_client_id(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'XAF']);
        $wallet->setClientGeneratedId('device-token:abc-123', $user);
        $user->setConfigValue('default-wallet', $wallet->fresh()->client_generated_id, ConfigValueType::String);

        $result = app(GetUserDefaultsTool::class)->handle([], $this->contextFor($user));

        $this->assertSame($wallet->id, $result['default_wallet']['id']);
    }

    public function test_defaults_tool_flags_an_unset_currency_instead_of_defaulting(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'GBP']);
        Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'NGN']);

        $result = app(GetUserDefaultsTool::class)->handle([], $this->contextFor($user));

        $this->assertFalse($result['currency_is_set']);
        $this->assertArrayNotHasKey('currency', $result);
        $this->assertStringContainsString('Ask which they want', $result['currency_note']);
    }

    public function test_convert_currency_uses_the_exchange_service(): void
    {
        $user = User::factory()->create();

        $rates = Mockery::mock(ExchangeRateService::class);
        $rates->shouldReceive('convert')->once()
            ->with(100.0, 'USD', 'XAF', Mockery::any())->andReturn(60000.0);
        $rates->shouldReceive('getRate')->once()
            ->with('USD', 'XAF', Mockery::any())->andReturn(600.0);

        $result = (new ConvertCurrencyTool($rates))->handle(
            ['amount' => 100, 'from' => 'usd', 'to' => 'xaf'],
            $this->contextFor($user),
        );

        $this->assertSame(60000.0, $result['converted']);
        $this->assertSame(600.0, $result['rate']);
        $this->assertSame('XAF', $result['to']);
    }

    public function test_convert_currency_refuses_to_estimate_a_missing_rate(): void
    {
        $user = User::factory()->create();

        $rates = Mockery::mock(ExchangeRateService::class);
        $rates->shouldReceive('convert')->andReturn(null);

        $result = (new ConvertCurrencyTool($rates))->handle(
            ['amount' => 100, 'from' => 'USD', 'to' => 'ZZZ'],
            $this->contextFor($user),
        );

        $this->assertStringContainsString('No exchange rate is available', $result['error']);
    }

    public function test_currency_tools_are_reachable_by_the_agent(): void
    {
        $names = app(TrakliHarness::class)->toolNames();

        $this->assertContains('get_user_defaults', $names);
        $this->assertContains('convert_currency', $names);
    }
}
