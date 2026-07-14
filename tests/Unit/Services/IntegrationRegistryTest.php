<?php

namespace Tests\Unit\Services;

use App\Contracts\Integration;
use App\Services\IntegrationRegistry;
use PHPUnit\Framework\TestCase;

class IntegrationRegistryTest extends TestCase
{
    private IntegrationRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new IntegrationRegistry();
    }

    public function test_registers_and_lists_integrations(): void
    {
        $this->registry->register($this->fakeIntegration('plaid'));
        $this->registry->register($this->fakeIntegration('kreuzberg'));

        $keys = array_map(fn (Integration $i) => $i->key(), $this->registry->all());

        $this->assertEqualsCanonicalizing(['plaid', 'kreuzberg'], $keys);
    }

    public function test_get_and_has_resolve_by_key(): void
    {
        $plaid = $this->fakeIntegration('plaid');
        $this->registry->register($plaid);

        $this->assertTrue($this->registry->has('plaid'));
        $this->assertSame($plaid, $this->registry->get('plaid'));
        $this->assertFalse($this->registry->has('unknown'));
        $this->assertNull($this->registry->get('unknown'));
    }

    public function test_registering_same_key_replaces_previous(): void
    {
        $this->registry->register($this->fakeIntegration('plaid'));
        $replacement = $this->fakeIntegration('plaid');
        $this->registry->register($replacement);

        $this->assertCount(1, $this->registry->all());
        $this->assertSame($replacement, $this->registry->get('plaid'));
    }

    private function fakeIntegration(string $key): Integration
    {
        $integration = $this->createMock(Integration::class);
        $integration->method('key')->willReturn($key);

        return $integration;
    }
}
