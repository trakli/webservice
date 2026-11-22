<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Http;
use Tests\CreatesApplication;
use Whilesmart\Admin\Offers\FreePasses;
use Whilesmart\Entitlements\Models\Plan;
use Whilesmart\Roles\Models\Role;

class AdminDashboardTest extends TestCase
{
    use CreatesApplication;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_dashboard_endpoints_require_authentication(): void
    {
        foreach (['features', 'posthog', 'offers'] as $endpoint) {
            $this->getJson('/api/v1/admin/'.$endpoint)->assertUnauthorized();
        }
    }

    public function test_dashboard_endpoints_require_an_admin(): void
    {
        $this->signIn(false);

        foreach (['features', 'posthog', 'offers'] as $endpoint) {
            $this->getJson('/api/v1/admin/'.$endpoint)->assertForbidden();
        }
    }

    public function test_admin_can_read_the_configured_shared_dashboard(): void
    {
        $this->signIn(true);
        $url = 'https://us.posthog.com/embedded/test-dashboard';
        config(['admin.posthog.dashboard_url' => $url]);

        $this->getJson('/api/v1/admin/features')->assertOk()->assertJsonPath('data.posthog', true);
        $this->getJson('/api/v1/admin/posthog')->assertOk()->assertJsonPath('data.dashboard_url', $url);
    }

    public function test_dashboard_stays_disabled_without_a_shared_https_url(): void
    {
        $this->signIn(true);

        foreach ([null, 'http://us.posthog.com/embedded/test', 'https://us.posthog.com/project/test'] as $url) {
            config(['admin.posthog.dashboard_url' => $url]);
            $this->getJson('/api/v1/admin/features')->assertOk()->assertJsonPath('data.posthog', false);
            $this->getJson('/api/v1/admin/posthog')->assertOk()->assertJsonPath('data.dashboard_url', null);
        }
    }

    public function test_admin_can_open_discounts_without_a_billing_plugin(): void
    {
        $this->signIn(true);
        config(['admin.offer_providers' => []]);

        $this->getJson('/api/v1/admin/offers')->assertOk()->assertJsonPath('data', []);
    }

    public function test_admin_can_grant_and_revoke_a_free_pass(): void
    {
        $this->signIn(true);
        config(['admin.offer_providers' => [FreePasses::class]]);
        $person = User::factory()->create();
        $plan = Plan::factory()->create();

        $created = $this->postJson('/api/v1/admin/offers/free-passes', ['attributes' => [
            'person' => $person->email,
            'plan' => $plan->key,
        ]])->assertCreated()->assertJsonPath('data.active', true);

        $this->getJson('/api/v1/admin/offers')->assertOk()
            ->assertJsonPath('data.0.offers.0.code', $person->email)
            ->assertJsonPath('data.0.offers.0.active', true);

        $this->deleteJson('/api/v1/admin/offers/free-passes/'.$created->json('data.id'))->assertOk();

        $this->getJson('/api/v1/admin/offers')->assertOk()->assertJsonPath('data.0.offers.0.active', false);
    }

    private function signIn(bool $admin): void
    {
        $user = User::factory()->create();
        if ($admin) {
            Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
            $user->assignRole('admin');
        }
        $this->actingAs($user, 'sanctum');
    }
}
