<?php

namespace App\Console\Commands\Plugin;

class InfoCommand extends PluginCommand
{
    protected $signature = 'plugin:info
        {id : The ID of the plugin to show state for}';

    protected $description = 'Show detailed information about a plugin';

    public function handle()
    {
        try {
            $pluginId = $this->argument('id');
            $plugin = $this->resolvePlugin($pluginId);

            $manifest = $plugin['manifest'] ?? [];

            $this->info('<fg=cyan>Plugin State:</>');
            $this->line('"'.$plugin['name'].'" (ID: '.$plugin['id'].')');
            $this->line(str_repeat('-', 60));

            // Basic Info
            $this->info("\n<fg=yellow>Basic Information:</>");
            $this->line('Name:        '.($plugin['name'] ?? 'N/A'));
            $this->line('Version:     '.($manifest['version'] ?? 'N/A'));
            $this->line('Namespace:   '.$plugin['namespace']);
            $this->line('Path:        '.$plugin['path']);
            $this->line('Status:      '.($plugin['enabled'] ? '<fg=green>Enabled</>' : '<fg=red>Disabled</>'));

            // Dependencies
            $this->info("\n<fg=yellow>Dependencies:</>");
            if (file_exists($plugin['path'].'/composer.json')) {
                $composerJson = json_decode(file_get_contents($plugin['path'].'/composer.json'), true);
                $this->line('Requires PHP:      '.($composerJson['require']['php'] ?? 'Not specified'));

                if (! empty($composerJson['require'])) {
                    unset($composerJson['require']['php']);
                    $this->line('Package Dependencies:');
                    foreach ($composerJson['require'] as $pkg => $version) {
                        $this->line("  - {$pkg}: {$version}");
                    }
                } else {
                    $this->line('No package dependencies');
                }
            } else {
                $this->line('No composer.json found');
            }

            // Service Provider
            $this->info("\n<fg=yellow>Service Provider:</>");
            $this->line($plugin['provider']);

            // Routes
            $this->info("\n<fg=yellow>Routes:</>");
            $routesPath = $plugin['path'].'/routes';
            if (is_dir($routesPath)) {
                $routeFiles = glob($routesPath.'/*.php');
                if (! empty($routeFiles)) {
                    foreach ($routeFiles as $file) {
                        $this->line('- '.basename($file).' ('.
                            number_format(filesize($file)).' bytes)');
                    }
                } else {
                    $this->line('No route files found');
                }
            } else {
                $this->line('No routes directory found');
            }

            // Configuration
            $this->info("\n<fg=yellow>Configuration:</>");
            $configPath = $plugin['path'].'/config';
            if (is_dir($configPath)) {
                $configFiles = glob($configPath.'/*.php');
                if (! empty($configFiles)) {
                    foreach ($configFiles as $file) {
                        $this->line('- '.basename($file).' ('.
                            number_format(filesize($file)).' bytes)');
                    }
                } else {
                    $this->line('No configuration files found');
                }
            } else {
                $this->line('No config directory found');
            }

            // Last modified
            $this->info("\n<fg=yellow>Last Modified:</>");
            $this->line('Manifest:    '.date('Y-m-d H:i:s', filemtime($plugin['path'].'/plugin.json')));

            return 0;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return 1;
        }
    }
}
