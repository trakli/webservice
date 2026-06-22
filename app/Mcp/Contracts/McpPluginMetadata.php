<?php

declare(strict_types=1);

namespace App\Mcp\Contracts;

/**
 * Metadata describing an MCP plugin.
 */
final class McpPluginMetadata
{
    /**
     * @param array<string> $dependencies
     * @param array<string> $permissions
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly array $dependencies = [],
        public readonly array $permissions = [],
    ) {}

    /**
     * Create from array (e.g., from plugin config).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            version: $data['version'] ?? '1.0.0',
            description: $data['description'] ?? '',
            dependencies: $data['dependencies'] ?? [],
            permissions: $data['permissions'] ?? [],
        );
    }

    /**
     * Convert to array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'description' => $this->description,
            'dependencies' => $this->dependencies,
            'permissions' => $this->permissions,
        ];
    }

    /**
     * Get the unique slug for this plugin (used for namespacing).
     */
    public function getSlug(): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '-', $this->name));
    }
}