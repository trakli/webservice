<?php

namespace Tests\Feature;

use App\Contracts\Entitlements;
use App\Contracts\Integration;
use App\Contracts\IntegrationUi;
use App\Models\User;
use App\Services\IntegrationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The registry is a boot-populated singleton; enabled plugins register
        // into it. Start each test from a clean registry so assertions are
        // deterministic regardless of which plugins are installed.
        $this->app->forgetInstance(IntegrationRegistry::class);
        $this->app->singleton(IntegrationRegistry::class);
    }

    public function test_lists_registered_integrations(): void
    {
        app(IntegrationRegistry::class)->register($this->fakeIntegration());

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/integrations')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'plaid')
            ->assertJsonPath('data.0.category', 'bank-sync')
            ->assertJsonPath('data.0.feature_key', 'plaid')
            ->assertJsonPath('data.0.configured', true)
            ->assertJsonPath('data.0.entitled', true)
            ->assertJsonPath('data.0.ui', null);
    }

    public function test_exposes_ui_descriptor_for_ui_integrations(): void
    {
        app(IntegrationRegistry::class)->register($this->fakeUiIntegration());

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/integrations')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'statement-import')
            ->assertJsonPath('data.0.ui.slots', ['settings.integrations', 'onboarding.steps'])
            ->assertJsonPath('data.0.ui.card.cta', 'Import')
            ->assertJsonPath('data.0.ui.onboarding.order', 60)
            ->assertJsonPath('data.0.ui.component', null);
    }

    public function test_entitled_reflects_entitlements_for_gated_integration(): void
    {
        app(IntegrationRegistry::class)->register($this->fakeIntegration());

        $this->mock(Entitlements::class, function ($mock) {
            $mock->shouldReceive('allows')->andReturnFalse();
        });

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/integrations')
            ->assertOk()
            ->assertJsonPath('data.0.entitled', false);
    }

    public function test_returns_empty_list_when_nothing_registered(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/integrations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/integrations')->assertUnauthorized();
    }

    private function fakeIntegration(): Integration
    {
        return new class () implements Integration {
            public function key(): string
            {
                return 'plaid';
            }

            public function name(): string
            {
                return 'Plaid';
            }

            public function description(): ?string
            {
                return 'Bank account sync';
            }

            public function category(): string
            {
                return 'bank-sync';
            }

            public function icon(): ?string
            {
                return null;
            }

            public function featureKey(): ?string
            {
                return 'plaid';
            }

            public function isConfigured(): bool
            {
                return true;
            }
        };
    }

    private function fakeUiIntegration(): IntegrationUi
    {
        return new class () implements IntegrationUi {
            public function key(): string
            {
                return 'statement-import';
            }

            public function name(): string
            {
                return 'Statement Import';
            }

            public function description(): ?string
            {
                return 'Import bank statements';
            }

            public function category(): string
            {
                return 'import';
            }

            public function icon(): ?string
            {
                return null;
            }

            public function featureKey(): ?string
            {
                return null;
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function ui(): array
            {
                return [
                    'slots' => ['settings.integrations', 'onboarding.steps'],
                    'card' => ['title' => 'Import bank statements', 'cta' => 'Import', 'description' => 'Upload a statement'],
                    'connect' => null,
                    'onboarding' => ['step' => 'import-statement', 'optional' => true, 'order' => 60],
                    'component' => null,
                ];
            }
        };
    }
}
