<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Whilesmart\Entitlements\Contracts\Entitlements;
use Whilesmart\Entitlements\Support\AllowAllEntitlements;

class PaidCreationLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Entitlements::class, new class () extends AllowAllEntitlements {
            public function limit(?Model $owner, string $key): ?int
            {
                return match ($key) {
                    'max_wallets' => 3,
                    'max_categories' => 10,
                    default => null,
                };
            }
        });
    }

    public function test_wallet_limit_is_enforced_through_http_without_deleting_data(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        for ($index = 0; $index < 3; $index++) {
            $this->postJson('/api/v1/wallets', [
                'name' => "Wallet {$index}", 'type' => 'bank', 'currency' => 'USD', 'balance' => 0,
            ])->assertCreated();
        }

        $this->postJson('/api/v1/wallets', [
            'name' => 'Fourth wallet', 'type' => 'bank', 'currency' => 'USD', 'balance' => 0,
        ])->assertStatus(403)->assertJsonPath('success', false)->assertJsonPath('errors.maximum', 3);
        $this->getJson('/api/v1/wallets')->assertOk();
        $this->assertSame(3, $user->wallets()->count());
    }

    public function test_seeded_categories_are_exempt_and_clients_cannot_claim_exemptions(): void
    {
        $user = User::factory()->create();
        Category::createSeeded(['name' => 'Default food', 'type' => 'expense', 'user_id' => $user->id]);
        $this->actingAs($user);

        for ($index = 0; $index < 10; $index++) {
            $this->postJson('/api/v1/categories', [
                'name' => "Custom {$index}", 'type' => 'expense', 'provenance' => 'seeded',
            ])->assertCreated();
        }

        $this->postJson('/api/v1/categories', [
            'name' => 'Eleventh custom', 'type' => 'expense', 'provenance' => 'grandfathered',
        ])->assertStatus(403)->assertJsonPath('errors.maximum', 10);
        $this->assertSame(10, Category::where('user_id', $user->id)->where('provenance', 'custom')->count());
        $this->assertSame(1, Category::where('user_id', $user->id)->where('provenance', 'seeded')->count());
    }

    public function test_http_seeded_defaults_do_not_spend_the_custom_category_allowance(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/categories/seed-defaults')->assertCreated();
        $this->assertSame(0, Category::where('user_id', $user->id)->where('provenance', 'custom')->count());
        $this->assertGreaterThan(10, Category::where('user_id', $user->id)->where('provenance', 'seeded')->count());

        $this->postJson('/api/v1/categories', ['name' => 'My custom category', 'type' => 'expense'])->assertCreated();
    }

    public function test_another_owner_has_an_independent_limit(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->actingAs($first);
        for ($index = 0; $index < 3; $index++) {
            $this->postJson('/api/v1/wallets', [
                'name' => "Wallet {$index}", 'type' => 'bank', 'currency' => 'USD', 'balance' => 0,
            ])->assertCreated();
        }

        $this->actingAs($second)->postJson('/api/v1/wallets', [
            'name' => 'Independent wallet', 'type' => 'bank', 'currency' => 'USD', 'balance' => 0,
        ])->assertCreated();
    }
}
