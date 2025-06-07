<?php

namespace App\Console\Commands\Plugin;

class DiscoverCommand extends PluginCommand
{
    protected $signature = 'plugin:discover';

    protected $description = 'Discover all available plugins';

    public function handle()
    {
        $count = $this->pluginManager->discover()->count();
        $this->info("Discovered {$count} plugins.");

        return $this->call('plugin:list');
    }
}
