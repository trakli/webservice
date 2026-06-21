<?php

namespace App\Services;

use App\Contracts\Integration;

class IntegrationRegistry
{
    /** @var array<string, Integration> */
    private array $integrations = [];

    public function register(Integration $integration): void
    {
        $this->integrations[$integration->key()] = $integration;
    }

    /**
     * @return Integration[]
     */
    public function all(): array
    {
        return array_values($this->integrations);
    }

    public function get(string $key): ?Integration
    {
        return $this->integrations[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->integrations[$key]);
    }
}
