<?php

namespace Tests\Feature;

use App\Contracts\Integration;
use App\Models\User;
use App\Services\IntegrationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_registered_integrations(): void
    {
        app(IntegrationRegistry::class)->register($this->fakeIntegration());

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/integrations')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'plaid')
            ->assertJsonPath('data.0.category', 'bank-sync')
            ->assertJsonPath('data.0.feature_key', 'plaid')
            ->assertJsonPath('data.0.configured', true);
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
}
