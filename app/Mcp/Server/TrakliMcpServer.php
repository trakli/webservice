<?php

declare(strict_types=1);

namespace App\Mcp\Server;

use App\Mcp\Plugins\McpPluginManager;
use Laravel\Mcp\Server as BaseServer;

class TrakliMcpServer extends BaseServer
{
    protected string $name = 'Trakli';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Trakli MCP Server provides access to personal finance data: wallets,
        transactions, categories, parties, and statistics. Write actions are
        gated by the connecting user's permissions.
        MARKDOWN;

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

    protected array $tools = [
        \App\Mcp\Tools\ListWalletsTool::class,
        \App\Mcp\Tools\ListTransactionsTool::class,
        \App\Mcp\Tools\ListCategoriesTool::class,
        \App\Mcp\Tools\ListPartiesTool::class,
        \App\Mcp\Tools\GetStatsTool::class,
        \App\Mcp\Tools\CreateWalletTool::class,
        \App\Mcp\Tools\RecordTransactionTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];

    /**
     * Fold plugin-provided capabilities into the server's own before it starts,
     * so a plugin's tools/resources/prompts are announced alongside the built-in
     * ones on the same connection.
     */
    protected function boot(): void
    {
        $plugins = app(McpPluginManager::class);

        $this->tools = $this->mergeUnique($this->tools, $plugins->getTools());
        $this->resources = $this->mergeUnique($this->resources, $plugins->getResources());
        $this->prompts = $this->mergeUnique($this->prompts, $plugins->getPrompts());
    }

    /**
     * @param  array<int, mixed>  $own
     * @param  array<string, class-string>  $plugin
     * @return array<int, mixed>
     */
    private function mergeUnique(array $own, array $plugin): array
    {
        return array_values(array_unique(array_merge($own, array_values($plugin))));
    }
}
