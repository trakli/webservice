<?php

namespace App\Console\Commands\Plugin;

use App\Services\PluginManager;
use Illuminate\Console\Command;

abstract class PluginCommand extends Command
{
    protected PluginManager $pluginManager;

    public function __construct(PluginManager $pluginManager)
    {
        parent::__construct();
        $this->pluginManager = $pluginManager;
    }

    /**
     * Get a plugin by ID or short name
     *
     * @param  string  $pluginId  Plugin ID or short name
     *
     * @throws \RuntimeException If plugin is not found or multiple plugins match
     */
    protected function resolvePlugin(string $pluginId): array
    {
        $plugin = $this->pluginManager->findPlugin($pluginId);

        if (! $plugin) {
            $suggestions = $this->getPluginSuggestions($pluginId);
            $message = "Plugin '{$pluginId}' not found.";

            if (! empty($suggestions)) {
                $message .= " Did you mean one of these?\n  - ".implode("\n  - ", $suggestions);
            } else {
                $message .= ' Use `plugin:list` to see available plugins.';
            }

            throw new \RuntimeException($message);
        }

        return $plugin;
    }

    /**
     * Get suggested plugin IDs based on input
     */
    protected function getPluginSuggestions(string $input): array
    {
        $allPlugins = $this->pluginManager->discover();
        $input = strtolower($input);
        $suggestions = [];

        foreach ($allPlugins as $plugin) {
            $id = strtolower($plugin['id']);
            if (str_contains($id, $input)) {
                $suggestions[] = $plugin['id'];
            }
        }

        return array_slice($suggestions, 0, 5); // Return max 5 suggestions
    }
}
