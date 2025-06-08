<?php

namespace App\Console\Commands\Plugin;

class EnableCommand extends PluginCommand
{
    protected $signature = 'plugin:enable
        {id : The ID of the plugin to enable}';

    protected $description = 'Enable a plugin';

    public function handle()
    {
        try {
            $plugin = $this->resolvePlugin($this->argument('id'));
            $pluginId = $plugin['id'];

            if ($this->pluginManager->isPluginEnabled($pluginId)) {
                $this->info("Plugin [{$plugin['name']}] is already enabled.");

                return 0;
            }

            $this->pluginManager->enablePlugin($pluginId);
            $this->info("Plugin [{$plugin['name']}] enabled successfully.");

            // Clear caches to ensure the plugin is immediately available
            $this->call('config:clear');
            $this->call('route:clear');

            return 0;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return 1;
        }
    }
}
