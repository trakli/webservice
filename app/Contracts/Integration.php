<?php

namespace App\Contracts;

/**
 * Declarative descriptor for an integration (bank sync, document analytics,
 * and so on). Plugins register one with the IntegrationRegistry so core can
 * list what is installed without knowing any specific vendor. Behaviour
 * (connect, sync) stays with the plugin; this layer is metadata only.
 */
interface Integration
{
    public function key(): string;

    public function name(): string;

    public function description(): ?string;

    public function category(): string;

    public function icon(): ?string;

    /**
     * Entitlement feature key this integration is gated by, or null when free.
     */
    public function featureKey(): ?string;

    /**
     * Whether the operator has supplied the configuration this integration
     * needs to run (API keys, service URL, and so on).
     */
    public function isConfigured(): bool;
}
