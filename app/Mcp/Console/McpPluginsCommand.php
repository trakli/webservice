<?php

declare(strict_types=1);

namespace App\Mcp\Console;

use App\Mcp\Plugins\McpPluginManager;
use Illuminate\Console\Command;

class McpPluginsCommand extends Command
{
    protected $signature = 'mcp:plugins
        {--format=table : Output format (table|json|verification)}
        {--verify : Run verification checks on all registered plugins}';

    protected $description = 'List and verify registered MCP plugins';

    public function handle(McpPluginManager $pluginManager): int
    {
        $plugins = $pluginManager->getRegisteredPlugins();

        if ($this->option('verify')) {
            return $this->runVerification($pluginManager, $plugins);
        }

        $format = $this->option('format');

        if ($format === 'json') {
            return $this->outputJson($pluginManager, $plugins);
        }

        return $this->outputTable($pluginManager, $plugins);
    }

    private function outputTable(McpPluginManager $manager, array $plugins): int
    {
        if ($plugins === []) {
            $this->warn('No MCP plugins are registered.');

            return 0;
        }

        $rows = [];
        foreach ($plugins as $slug => $metadata) {
            $tools = count($manager->getPlugin($slug)?->registerTools() ?? []);
            $resources = count($manager->getPlugin($slug)?->registerResources() ?? []);
            $prompts = count($manager->getPlugin($slug)?->registerPrompts() ?? []);

            $rows[] = [
                $slug,
                $metadata->name,
                $metadata->version,
                $tools,
                $resources,
                $prompts,
                implode(', ', $metadata->permissions),
            ];
        }

        $this->table(
            ['Slug', 'Name', 'Version', 'Tools', 'Resources', 'Prompts', 'Permissions'],
            $rows,
        );

        $this->newLine();
        $message = sprintf(
            'Total: %d plugin(s) (%d tools, %d resources, %d prompts)',
            count($plugins),
            count($manager->getTools()),
            count($manager->getResources()),
            count($manager->getPrompts()),
        );
        $this->info($message);

        return 0;
    }

    private function outputJson(McpPluginManager $manager, array $plugins): int
    {
        $data = [
            'total_plugins' => count($plugins),
            'total_tools' => count($manager->getTools()),
            'total_resources' => count($manager->getResources()),
            'total_prompts' => count($manager->getPrompts()),
            'plugins' => [],
        ];

        foreach ($plugins as $slug => $metadata) {
            $data['plugins'][$slug] = [
                'name' => $metadata->name,
                'version' => $metadata->version,
                'description' => $metadata->description,
                'dependencies' => $metadata->dependencies,
                'permissions' => $metadata->permissions,
                'tools' => count($manager->getPlugin($slug)?->registerTools() ?? []),
                'resources' => count($manager->getPlugin($slug)?->registerResources() ?? []),
                'prompts' => count($manager->getPlugin($slug)?->registerPrompts() ?? []),
            ];
        }

        $this->line(json_encode($data, JSON_PRETTY_PRINT));

        return 0;
    }

    private function runVerification(McpPluginManager $manager, array $plugins): int
    {
        $this->info('Running MCP plugin verification...');
        $this->newLine();

        if ($plugins === []) {
            $this->warn('[SKIP] No plugins registered — nothing to verify.');

            return 0;
        }

        $contractsPass = $this->verifyPluginContracts($manager, $plugins);
        $this->verifyCapabilityAggregation($manager, $plugins);
        $this->detectCollisions($manager);

        // 4. Total aggregated capabilities
        $this->newLine();
        $this->line('4. Aggregated Totals:');
        $this->line('   Tools: ' . count($manager->getTools()));
        $this->line('   Resources: ' . count($manager->getResources()));
        $this->line('   Prompts: ' . count($manager->getPrompts()));

        $this->newLine();
        if ($contractsPass) {
            $this->info('Verification: PASS');
            return 0;
        }

        $this->error('Verification: FAIL');
        return 1;
    }

    /**
     * @param array<string, \App\Mcp\Contracts\McpPluginMetadata> $plugins
     */
    private function verifyPluginContracts(McpPluginManager $manager, array $plugins): bool
    {
        $this->line('1. Plugin Contract Checks:');
        $allPass = true;

        foreach ($plugins as $slug => $metadata) {
            $plugin = $manager->getPlugin($slug);

            $this->output->write("   {$slug}: ");

            if ($plugin === null) {
                $this->error('FAIL (not retrievable)');
                $allPass = false;

                continue;
            }

            $checks = [
                'name' => ! empty($metadata->name),
                'version' => ! empty($metadata->version) && preg_match('/^\d+\.\d+\.\d+$/', $metadata->version),
                'tools_array' => collect($plugin->registerTools())->every(fn ($t) => class_exists((string) $t)),
                'resources_array' => collect($plugin->registerResources())->every(fn ($t) => class_exists((string) $t)),
                'prompts_array' => collect($plugin->registerPrompts())->every(fn ($t) => class_exists((string) $t)),
                'slug' => ! empty($slug) && preg_match('/^[a-z0-9-]+$/', $slug),
            ];

            $failures = array_filter($checks, fn ($isValid) => ! $isValid);
            if ($failures === []) {
                $this->info('PASS');
            } else {
                $this->error('FAIL (' . implode(', ', array_keys($failures)) . ')');
                $allPass = false;
            }
        }

        return $allPass;
    }

    /**
     * @param array<string, \App\Mcp\Contracts\McpPluginMetadata> $plugins
     */
    private function verifyCapabilityAggregation(McpPluginManager $manager, array $plugins): void
    {
        $this->newLine();
        $this->line('2. Capability Aggregation:');
        foreach (array_keys($plugins) as $slug) {
            $plugin = $manager->getPlugin($slug);

            $toolCount = count($plugin?->registerTools() ?? []);
            $resourceCount = count($plugin?->registerResources() ?? []);
            $promptCount = count($plugin?->registerPrompts() ?? []);

            $this->line("   {$slug}: {$toolCount} tool(s), {$resourceCount} resource(s), {$promptCount} prompt(s)");
        }
    }

    private function detectCollisions(McpPluginManager $manager): void
    {
        $this->newLine();
        $this->line('3. Collision Detection:');
        $allNames = [];
        $collisions = [];
        foreach (array_keys($manager->getAllCapabilities()) as $prefixedName) {
            $base = preg_replace('/^[a-z0-9-]+\./', '', (string) $prefixedName);
            if (isset($allNames[$base])) {
                $collisions[] = "{$base} (from {$allNames[$base]} and {$prefixedName})";
            }
            $allNames[$base] = $prefixedName;
        }

        if ($collisions === []) {
            $this->info('   No collisions detected');
        } else {
            foreach ($collisions as $collision) {
                $this->warn("   Collision: {$collision}");
            }
        }
    }
}
