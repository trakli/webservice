<?php

namespace App\Console\Commands\Plugin;

class DisableCommand extends PluginCommand
{
    protected $signature = 'plugin:disable
        {id : The ID of the plugin to disable}';

    protected $description = 'Disable a plugin';

    public function handle()
    {
        try {
            $plugin = $this->resolvePlugin($this->argument('id'));
            $pluginId = $plugin['id'];

            if (! $this->pluginManager->isPluginEnabled($pluginId)) {
                $this->info("Plugin [{$plugin['name']}] is already disabled.");

                return 0;
            }

            $this->pluginManager->disablePlugin($pluginId);
            $this->info("Plugin [{$plugin['name']}] disabled successfully.");

            // Clear caches to ensure the plugin is immediately unavailable
            $this->call('config:clear');
            $this->call('route:clear');

            return 0;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return 1;
        }
    }
}
