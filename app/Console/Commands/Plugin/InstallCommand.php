<?php

namespace App\Console\Commands\Plugin;

class InstallCommand extends PluginCommand
{
    protected $signature = 'plugin:install
        {id : The ID of the plugin to install}
        {--no-deps : Skip installing dependencies}';

    protected $description = 'Install a plugin and its dependencies';

    public function handle()
    {
        try {
            $plugin = $this->resolvePlugin($this->argument('id'));
            $pluginId = $plugin['id'];

            $this->info("Installing plugin: {$plugin['name']}");

            if (file_exists("{$plugin['path']}/composer.json") && ! $this->option('no-deps')) {
                $this->info("Installing dependencies for plugin [{$plugin['name']}]...");

                if ($this->pluginManager->installDependencies($pluginId)) {
                    $this->info("Dependencies installed successfully for [{$plugin['name']}].");

                    // Enable the plugin after installation if not already enabled
                    if (! $this->pluginManager->isPluginEnabled($pluginId)) {
                        $this->info("Enabling plugin [{$plugin['name']}]...");
                        $this->pluginManager->enablePlugin($pluginId);
                        $this->info("Plugin [{$plugin['name']}] enabled successfully.");

                        // Clear caches to ensure the plugin is immediately available
                        $this->call('config:clear');
                        $this->call('route:clear');
                    }

                    $this->info("Plugin [{$plugin['name']}] installed successfully.");

                    return 0;
                }

                $this->error("Failed to install dependencies for [{$plugin['name']}].");

                return 1;

            } else {
                $this->info("Plugin [{$plugin['name']}] installed successfully.");

                return 0;
            }

        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return 1;
        }
    }
}
