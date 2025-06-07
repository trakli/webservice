<?php

namespace App\Console\Commands\Plugin;

class ListCommand extends PluginCommand
{
    protected $signature = 'plugin:list';

    protected $description = 'List all available plugins';

    public function handle()
    {
        $plugins = $this->pluginManager->discover();

        if ($plugins->isEmpty()) {
            $this->info('No plugins found.');

            return 0;
        }

        $rows = $plugins->map(function ($plugin) {
            return [
                'id' => $plugin['id'] ?? '',
                'name' => $plugin['name'],
                'version' => $plugin['manifest']['version'] ?? '1.0.0',
                'status' => $plugin['enabled'] ? '<fg=green>Enabled</>' : '<fg=red>Disabled</>',
                'description' => $plugin['manifest']['description'] ?? 'No description',
            ];
        })->toArray();

        $this->table(
            ['ID', 'Name', 'Version', 'Status', 'Description'],
            $rows
        );

        return 0;
    }
}
