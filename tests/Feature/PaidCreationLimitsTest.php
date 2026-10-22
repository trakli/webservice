<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Support\CategoryAllowance;
use Illuminate\Support\Facades\Schema;
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
                    'max_categories' => 5,
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

        for ($index = 0; $index < 5; $index++) {
            $this->postJson('/api/v1/categories', [
                'name' => "Custom {$index}", 'type' => 'expense', 'provenance' => 'seeded',
            ])->assertCreated();
        }

        $this->postJson('/api/v1/categories', [
            'name' => 'Sixth custom', 'type' => 'expense', 'provenance' => 'grandfathered',
        ])->assertStatus(403)->assertJsonPath('errors.maximum', 5);
        $this->assertSame(5, app(CategoryAllowance::class)->count($user));
        $this->assertSame(6, $user->categories()->count());
    }

    public function test_http_seeded_defaults_do_not_spend_the_custom_category_allowance(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/categories/seed-defaults')->assertCreated();
        $this->assertSame(0, app(CategoryAllowance::class)->count($user));
        $this->assertGreaterThan(10, $user->categories()->count());

        $this->postJson('/api/v1/categories', ['name' => 'My custom category', 'type' => 'expense'])->assertCreated();
    }

    public function test_defaults_can_be_renamed_deleted_and_reseeded_at_the_limit(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/categories/seed-defaults')->assertCreated();
        $default = $user->categories()->first();
        $this->putJson('/api/v1/categories/'.$default->id, [
            'name' => 'Renamed default', 'type' => $default->type,
        ])->assertOk();
        $this->deleteJson('/api/v1/categories/'.$default->id)->assertNoContent();

        for ($index = 0; $index < 5; $index++) {
            $this->postJson('/api/v1/categories', [
                'name' => "Custom {$index}", 'type' => 'expense',
            ])->assertCreated();
        }

        $this->postJson('/api/v1/categories/seed-defaults')->assertCreated();
        $this->assertSame(5, app(CategoryAllowance::class)->count($user));
        $custom = $user->categories()->where('name', 'Custom 0')->first();
        $this->deleteJson('/api/v1/categories/'.$custom->id)->assertNoContent();
        $this->postJson('/api/v1/categories', [
            'name' => 'Replacement custom', 'type' => 'expense',
        ])->assertCreated();
        $this->postJson('/api/v1/categories', [
            'name' => 'Still over limit', 'type' => 'expense',
        ])->assertForbidden();
    }

    public function test_allowance_configuration_cannot_be_read_changed_or_deleted_by_clients(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/categories', [
            'name' => 'Custom', 'type' => 'expense',
        ])->assertCreated();
        $key = CategoryAllowance::KEY;
        $this->getJson('/api/v1/configurations/'.$key)->assertNotFound();
        $this->deleteJson('/api/v1/configurations/'.$key)->assertNotFound();
        $this->putJson('/api/v1/configurations/'.$key, [
            'type' => 'array', 'value' => [],
        ])->assertUnprocessable();
        $this->postJson('/api/v1/configurations', [
            'key' => $key, 'type' => 'array', 'value' => [1],
        ])->assertUnprocessable();
        $this->getJson('/api/v1/configurations')->assertOk()->assertDontSee($key);
        $this->assertSame(1, app(CategoryAllowance::class)->count($user));
    }

    public function test_existing_categories_keep_their_allowance_without_a_category_column(): void
    {
        $this->assertFalse(Schema::hasColumn('categories', 'provenance'));
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/categories', [
            'name' => 'Existing category', 'type' => 'expense',
        ])->assertCreated();
        \Whilesmart\ModelConfiguration\Models\Configuration::where('configurable_id', $user->id)
            ->where('configurable_type', $user->getMorphClass())->where('key', CategoryAllowance::KEY)->delete();
        $migration = require database_path('migrations/2026_10_22_000001_preserve_category_allowances.php');
        $migration->up();
        $this->assertSame(0, app(CategoryAllowance::class)->count($user));
        $this->postJson('/api/v1/categories', [
            'name' => 'New category', 'type' => 'expense',
        ])->assertCreated();
        $this->assertSame(1, app(CategoryAllowance::class)->count($user));
        $migration->up();
        $this->assertSame(1, app(CategoryAllowance::class)->count($user));
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
