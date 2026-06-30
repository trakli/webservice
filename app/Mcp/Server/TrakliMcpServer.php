<?php

declare(strict_types=1);

namespace App\Mcp\Server;

use App\Mcp\Plugins\McpPluginManager;
use Laravel\Mcp\Enums\ProtocolVersion;
use Laravel\Mcp\Schema\Icon;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server as BaseServer;

class TrakliMcpServer extends BaseServer
{
    protected string $name = 'Trakli';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Trakli MCP Server provides access to personal finance data.
        
        The server is extensible via plugins. Tools, resources, and prompts
        are registered separately and announced in the capability manifest.
    MARKDOWN;

    protected array $supportedProtocolVersion = [
        ProtocolVersion::V2025_06_18->value,
    ];

    protected array $tools = [];

    protected array $resources = [];

    protected array $prompts = [];

    protected array $capabilities = [
        self::CAPABILITY_TOOLS => [
            'listChanged' => true,
        ],
        self::CAPABILITY_RESOURCES => [
            'listChanged' => true,
        ],
        self::CAPABILITY_PROMPTS => [
            'listChanged' => true,
        ],
    ];

    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
    }

    /**
     * Merge plugin capabilities into the server.
     */
    public function mergePluginCapabilities(McpPluginManager $pluginManager): void
    {
        $pluginTools = $pluginManager->getTools();
        $pluginResources = $pluginManager->getResources();
        $pluginPrompts = $pluginManager->getPrompts();

        $this->tools = array_merge($this->tools, array_values($pluginTools));
        $this->resources = array_merge($this->resources, array_values($pluginResources));
        $this->prompts = array_merge($this->prompts, array_values($pluginPrompts));
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * @return array<int, string>
     */
    public function getSupportedProtocolVersion(): array
    {
        return $this->supportedProtocolVersion;
    }

    public function getInstructions(): string
    {
        return $this->instructions;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * @return array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * @return array<int, class-string<\Laravel\Mcp\Server\Resource>>
     */
    public function getResources(): array
    {
        return $this->resources;
    }

    /**
     * @return array<int, class-string<\Laravel\Mcp\Server\Prompt>>
     */
    public function getPrompts(): array
    {
        return $this->prompts;
    }

    /**
     * @return list<Icon>
     */
    protected function icons(): array
    {
        return [
            new Icon(
                src: '/icon.svg',
                mimeType: 'image/svg+xml',
                sizes: ['1024x1024']
            ),
        ];
    }
}
