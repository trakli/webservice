<?php

declare(strict_types=1);

namespace App\Mcp\Contracts;

use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\Tool;

/**
 * Contract for plugins that provide MCP capabilities.
 *
 * Plugins implementing this interface will have their tools, resources,
 * and prompts automatically registered with the MCP server.
 */
interface ProvidesMcpCapabilities
{
    /**
     * Get the plugin metadata.
     */
    public function getMcpMetadata(): McpPluginMetadata;

    /**
     * Register MCP tools provided by this plugin.
     *
     * @return array<class-string<Tool>>
     */
    public function registerTools(): array;

    /**
     * Register MCP resources provided by this plugin.
     *
     * @return array<class-string<Resource>>
     */
    public function registerResources(): array;

    /**
     * Register MCP prompts provided by this plugin.
     *
     * @return array<class-string<Prompt>>
     */
    public function registerPrompts(): array;
}