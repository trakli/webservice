<?php

declare(strict_types=1);

namespace App\Mcp\Plugins;

use App\Mcp\Auth\McpGateRegistrar;
use App\Mcp\Contracts\McpPluginMetadata;
use App\Mcp\Contracts\ProvidesMcpCapabilities;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\Tool;

/**
 * Manages MCP plugin discovery, registration, and capability aggregation.
 */
final class McpPluginManager
{
    /**
     * @var array<string, ProvidesMcpCapabilities>
     */
    private array $registeredPlugins = [];

    /**
     * @var array<int, array<string, mixed>>|null Cached parsed composer.lock packages.
     */
    private ?array $installedPackages = null;

    /**
     * @var array<string, class-string<Tool>>
     */
    private array $aggregatedTools = [];

    /**
     * @var array<string, class-string<Resource>>
     */
    private array $aggregatedResources = [];

    /**
     * @var array<string, class-string<Prompt>>
     */
    private array $aggregatedPrompts = [];

    public function __construct()
    {
        $this->discoverPlugins();
    }

    /**
     * Discover and register all MCP plugins.
     */
    private function discoverPlugins(): void
    {
        $pluginClasses = $this->getPluginClasses();

        foreach ($pluginClasses as $class) {
            try {
                $this->registerPlugin($class);
            } catch (\Throwable $e) {
                Log::error("Failed to register MCP plugin {$class}: {$e->getMessage()}", [
                    'plugin' => $class,
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * Get all plugin class names from config and auto-discovery.
     *
     * @return array<string>
     */
    private function getPluginClasses(): array
    {
        $configured = Config::get('mcp.plugins', []);
        $discovered = $this->autoDiscoverPlugins();

        return array_values(array_unique(array_merge($configured, $discovered)));
    }

    /**
     * Auto-discover plugins from installed packages.
     *
     * @return array<string>
     */
    private function autoDiscoverPlugins(): array
    {
        $plugins = [];

        // Check for packages with mcp-plugin extra in composer.json
        $installedPackages = $this->getInstalledPackages();

        foreach ($installedPackages as $package) {
            $extra = $package['extra'] ?? [];
            $mcpPlugin = $extra['mcp-plugin'] ?? null;

            if ($mcpPlugin && isset($mcpPlugin['class'])) {
                $plugins[] = $mcpPlugin['class'];
            }
        }

        return $plugins;
    }

    /**
     * Get installed packages from composer.
     *
     * Results are cached after the first read to avoid repeated file I/O,
     * particularly important during plugin dependency validation which calls
     * this method once per plugin dependency.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getInstalledPackages(): array
    {
        if ($this->installedPackages !== null) {
            return $this->installedPackages;
        }

        $composerLockPath = base_path('composer.lock');

        if (! file_exists($composerLockPath)) {
            return $this->installedPackages = [];
        }

        $lockData = json_decode(file_get_contents($composerLockPath), true);
        $packages = $lockData['packages'] ?? [];

        return $this->installedPackages = array_merge($packages, $lockData['packages-dev'] ?? []);
    }

    /**
     * Register a plugin class.
     */
    private function registerPlugin(string $class): void
    {
        if (! class_exists($class)) {
            throw new \InvalidArgumentException("Plugin class {$class} does not exist");
        }

        if (! is_subclass_of($class, ProvidesMcpCapabilities::class)) {
            throw new \InvalidArgumentException("Plugin class {$class} must implement " . ProvidesMcpCapabilities::class);
        }

        /** @var ProvidesMcpCapabilities $plugin */
        $plugin = App::make($class);
        $metadata = $plugin->getMcpMetadata();
        $slug = $metadata->getSlug();

        if (isset($this->registeredPlugins[$slug])) {
            throw new \RuntimeException("Duplicate MCP plugin slug: {$slug}");
        }

        $this->validateDependencies($metadata);
        $this->validatePermissions($metadata);

        // Register tools with plugin prefix to avoid collisions
        foreach ($plugin->registerTools() as $toolClass) {
            $prefixedName = $this->getPrefixedName($slug, $toolClass);
            $this->aggregatedTools[$prefixedName] = $toolClass;
        }

        // Register resources with plugin prefix
        foreach ($plugin->registerResources() as $resourceClass) {
            $prefixedName = $this->getPrefixedName($slug, $resourceClass);
            $this->aggregatedResources[$prefixedName] = $resourceClass;
        }

        // Register prompts with plugin prefix
        foreach ($plugin->registerPrompts() as $promptClass) {
            $prefixedName = $this->getPrefixedName($slug, $promptClass);
            $this->aggregatedPrompts[$prefixedName] = $promptClass;
        }

        $this->registeredPlugins[$slug] = $plugin;

        Log::info("Registered MCP plugin: {$metadata->name} v{$metadata->version} ({$slug})", [
            'tools' => count($plugin->registerTools()),
            'resources' => count($plugin->registerResources()),
            'prompts' => count($plugin->registerPrompts()),
        ]);
    }

    /**
     * Validate plugin dependencies are met.
     */
    private function validateDependencies(McpPluginMetadata $metadata): void
    {
        foreach ($metadata->dependencies as $dependency) {
            if (! isset($this->registeredPlugins[$dependency])) {
                // Check if it's a package dependency
                if (! $this->isPackageInstalled($dependency)) {
                    throw new \RuntimeException(
                        "Plugin {$metadata->name} requires dependency: {$dependency}"
                    );
                }
            }
        }
    }

    /**
     * Check if a package is installed.
     */
    private function isPackageInstalled(string $packageName): bool
    {
        $packages = $this->getInstalledPackages();

        foreach ($packages as $package) {
            if ($package['name'] === $packageName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate plugin permissions are configured and gates are registered.
     *
     * Throws if strict mode is enabled and a permission has no registered gate.
     * Logs warnings for unknown permissions in non-strict mode.
     *
     * @throws \RuntimeException when strict validation fails
     */
    private function validatePermissions(McpPluginMetadata $metadata): void
    {
        $strictMode = Config::get('mcp.auth.strict_permissions', false);
        $configuredPermissions = Config::get('mcp.permissions', []);
        $hasWarnings = false;

        foreach ($metadata->permissions as $permission) {
            $isConfigured = isset($configuredPermissions[$permission]);
            $gateExists = Gate::has($permission) || Gate::has($configuredPermissions[$permission]['gate'] ?? $permission);

            if (! $isConfigured) {
                Log::warning("MCP plugin {$metadata->name} declares unknown permission: {$permission}");

                if ($strictMode) {
                    throw new \RuntimeException(
                        "MCP plugin {$metadata->name} declares permission '{$permission}' " .
                        'which is not defined in config/mcp.php'
                    );
                }
                $hasWarnings = true;
            }

            if ($strictMode && ! $gateExists) {
                throw new \RuntimeException(
                    "MCP plugin {$metadata->name} requires permission '{$permission}' " .
                    "but no Gate is registered for it"
                );
            }

            if (! $gateExists) {
                Log::warning("MCP plugin {$metadata->name} declares permission '{$permission}' but no Gate is registered");
                $hasWarnings = true;
            }
        }

        // Register plugin permissions as gates if they don't exist yet
        McpGateRegistrar::registerPluginPermissions($metadata->permissions);
    }

    /**
     * Get prefixed name for capability to avoid collisions.
     */
    private function getPrefixedName(string $slug, string $class): string
    {
        $shortName = class_basename($class);
        $shortName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $shortName));
        $shortName = str_replace(['tool', 'resource', 'prompt'], '', $shortName);
        $shortName = trim($shortName, '_');

        return "{$slug}.{$shortName}";
    }

    /**
     * Get all aggregated tools.
     *
     * @return array<string, class-string<Tool>>
     */
    public function getTools(): array
    {
        return $this->aggregatedTools;
    }

    /**
     * Get all aggregated resources.
     *
     * @return array<string, class-string<Resource>>
     */
    public function getResources(): array
    {
        return $this->aggregatedResources;
    }

    /**
     * Get all aggregated prompts.
     *
     * @return array<string, class-string<Prompt>>
     */
    public function getPrompts(): array
    {
        return $this->aggregatedPrompts;
    }

    /**
     * Get registered plugins metadata.
     *
     * @return array<string, McpPluginMetadata>
     */
    public function getRegisteredPlugins(): array
    {
        return array_map(
            fn (ProvidesMcpCapabilities $plugin) => $plugin->getMcpMetadata(),
            $this->registeredPlugins
        );
    }

    /**
     * Get a specific plugin by slug.
     */
    public function getPlugin(string $slug): ?ProvidesMcpCapabilities
    {
        return $this->registeredPlugins[$slug] ?? null;
    }

    /**
     * Check if a plugin is registered.
     */
    public function hasPlugin(string $slug): bool
    {
        return isset($this->registeredPlugins[$slug]);
    }

    /**
     * Get all capabilities as a flat array for the server.
     *
     * @return array<string, class-string<Tool|Resource|Prompt>>
     */
    public function getAllCapabilities(): array
    {
        return array_merge(
            $this->aggregatedTools,
            $this->aggregatedResources,
            $this->aggregatedPrompts
        );
    }
}