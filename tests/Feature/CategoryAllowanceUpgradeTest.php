<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CategoryAllowance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Whilesmart\Entitlements\Contracts\Entitlements;
use Whilesmart\Entitlements\Support\AllowAllEntitlements;
use Whilesmart\ModelConfiguration\Models\Configuration;

class CategoryAllowanceUpgradeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('categories', fn (Blueprint $table) => $table->string('provenance')->default('custom'));
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('provenance'));
        parent::tearDown();
    }

    public function test_upgrade_preserves_defaults_existing_exemptions_and_custom_usage(): void
    {
        $this->app->instance(Entitlements::class, new class () extends AllowAllEntitlements {
            public function limit(?Model $owner, string $key): ?int
            {
                return $key === 'max_categories' ? 5 : null;
            }
        });
        $user = User::factory()->create();
        $defaults = $this->actingAs($user)->postJson('/api/v1/categories/seed-defaults')->assertCreated()
            ->json('data.categories');
        $existing = $this->postJson('/api/v1/categories', [
            'name' => 'Existing category', 'type' => 'expense',
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/categories', [
            'name' => 'Custom category', 'type' => 'expense',
        ])->assertCreated();

        DB::table('categories')->whereIn('id', array_column($defaults, 'id'))->update(['provenance' => 'seeded']);
        DB::table('categories')->where('id', $existing)->update(['provenance' => 'grandfathered']);
        Configuration::where('configurable_type', $user->getMorphClass())->where('configurable_id', $user->id)
            ->where('key', CategoryAllowance::KEY)->delete();

        $migration = require database_path('migrations/2026_10_22_000001_preserve_category_allowances.php');
        $migration->up();
        $this->assertSame(1, app(CategoryAllowance::class)->count($user));
        for ($index = 0; $index < 4; $index++) {
            $this->postJson('/api/v1/categories', [
                'name' => "New custom {$index}", 'type' => 'expense',
            ])->assertCreated();
        }
        $this->postJson('/api/v1/categories', [
            'name' => 'Sixth custom', 'type' => 'expense',
        ])->assertForbidden();
        $this->assertSame(count($defaults) + 6, $user->categories()->count());
        $this->assertTrue(Schema::hasColumn('categories', 'provenance'));
        $this->getJson('/api/v1/categories')->assertOk()->assertDontSee('provenance');
    }
}
