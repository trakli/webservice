<?php

namespace App\Services;

use Composer\Autoload\ClassLoader;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class PluginManager
{
    protected Application $app;

    protected string $pluginsPath;

    protected array $plugins = [];

    protected ClassLoader $classLoader;

    protected $output;

    public function __construct(Application $app, $output = null)
    {
        $this->app = $app;
        $this->pluginsPath = base_path('plugins');
        $this->classLoader = require base_path('vendor/autoload.php');
        $this->output = $output ?? new OutputStyle(new ArrayInput([]), new NullOutput);
    }

    /**
     * Find a plugin by its ID (case-insensitive)
     */
    public function findPlugin(string $pluginId): ?array
    {
        $pluginId = trim($pluginId);
        if (empty($pluginId)) {
            return null;
        }

        $plugins = $this->discover();

        return $plugins->first(fn ($p) => strtolower($p['id']) === strtolower($pluginId));
    }

    public function discover(): Collection
    {
        if (! empty($this->plugins)) {
            Log::debug('Returning cached plugins');

            return collect($this->plugins);
        }

        Log::debug('Starting plugin discovery', ['path' => $this->pluginsPath]);

        if (! is_dir($this->pluginsPath)) {
            Log::warning("Plugins directory not found: {$this->pluginsPath}");

            return collect();
        }

        $plugins = [];
        $directories = new \DirectoryIterator($this->pluginsPath);
        $foundDirs = [];

        foreach ($directories as $directory) {
            $foundDirs[] = $directory->getBasename();

            if (! $directory->isDir() || $directory->isDot()) {
                Log::debug('Skipping non-directory or dot file', ['path' => $directory->getPathname()]);

                continue;
            }

            $pluginPath = $directory->getPathname();
            $manifestPath = $pluginPath.'/plugin.json';
            Log::debug('Checking plugin directory', ['path' => $pluginPath]);

            if (! file_exists($manifestPath)) {
                Log::debug("Plugin manifest not found in: {$pluginPath}");

                continue;
            }

            Log::debug('Found plugin manifest', ['path' => $manifestPath]);
            $plugin = $this->loadPlugin($pluginPath);

            if ($plugin) {
                $expectedDirName = $plugin['id'] ?? null;
                $actualDirName = $directory->getBasename();

                if ($expectedDirName !== $actualDirName) {
                    Log::warning(sprintf(
                        'Plugin directory name "%s" does not match plugin ID "%s"',
                        $actualDirName,
                        $expectedDirName
                    ));

                    continue;
                }

                $plugins[] = $plugin;
                Log::debug('Successfully loaded plugin', ['id' => $plugin['id']]);
            }
        }

        Log::debug('Finished plugin discovery', [
            'found_plugins' => count($plugins),
            'scanned_directories' => $foundDirs,
        ]);

        $this->plugins = $plugins;

        return collect($plugins);
    }

    /**
     * Load a single plugin
     */
    protected function loadPlugin(string $path): ?array
    {
        $manifestPath = "{$path}/plugin.json";

        if (! File::exists($manifestPath)) {
            Log::warning("Plugin manifest not found: {$manifestPath}");

            return null;
        }

        Log::debug("Loading plugin from: {$path}");

        try {
            $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);

            if (! isset($manifest['provider'])) {
                Log::warning("Plugin manifest missing required 'provider' field: {$manifestPath}");

                return null;
            }

            if (! isset($manifest['id'])) {
                Log::warning("Plugin manifest missing required 'id' field: {$manifestPath}");

                return null;
            }

            $providerClass = $manifest['provider'];
            $namespace = rtrim($manifest['namespace'] ?? basename($path), '\\').'\\';

            $this->registerPluginAutoloading($namespace, $path);

            if (! class_exists($providerClass)) {
                Log::warning("Plugin provider class not found: {$providerClass} in {$path}");

                return null;
            }

            return [
                'id' => $manifest['id'],
                'name' => $manifest['name'] ?? basename($path),
                'path' => $path,
                'namespace' => $namespace,
                'manifest' => $manifest,
                'provider' => $providerClass,
                'enabled' => $manifest['enabled'] ?? true,
            ];
        } catch (\JsonException $e) {
            Log::error("Failed to parse plugin manifest: {$manifestPath} - ".$e->getMessage());

            return null;
        }
    }

    /**
     * Register plugin autoloading.
     *
     * @param  string  $namespace  The plugin's namespace
     * @param  string  $path  Path to the plugin directory
     *
     * @throws \RuntimeException If plugin autoloading fails
     */
    protected function registerPluginAutoloading(string $namespace, string $path): void
    {
        $composerAutoload = "{$path}/vendor/autoload.php";

        if (file_exists($composerAutoload)) {
            try {
                $loader = require $composerAutoload;
                $this->app->instance('plugin.loader.'.basename($path), $loader);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "Failed to load plugin autoloader for {$namespace}: ".$e->getMessage(),
                    0,
                    $e
                );
            }
        } else {
            $this->classLoader->addPsr4($namespace, "{$path}/src");
        }
    }

    /**
     * Install plugin dependencies using Composer.
     *
     * @param  string  $pluginId  The plugin ID
     * @return bool True if dependencies were installed successfully, false otherwise
     *
     * @throws \RuntimeException If the plugin is not found or dependency installation fails
     */
    public function installDependencies(string $pluginId): bool
    {
        $plugin = $this->findPlugin($pluginId);
        if (! $plugin) {
            throw new \RuntimeException("Plugin {$pluginId} not found");
        }

        $pluginPath = $plugin['path'];
        $composerJson = $pluginPath.'/composer.json';

        if (! file_exists($composerJson)) {
            return true;
        }

        $this->output->writeln("<info>Installing dependencies for plugin:</info> {$plugin['name']}");

        if (! is_dir($pluginPath.'/vendor')) {
            if (! mkdir($pluginPath.'/vendor', 0755, true) && ! is_dir($pluginPath.'/vendor')) {
                throw new \RuntimeException("Failed to create vendor directory for plugin {$pluginId}");
            }
        }

        try {
            $process = new Process(
                ['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction'],
                $pluginPath,
                null,
                null,
                300
            );

            $process->run(function ($type, $buffer) {
                if ($type === Process::ERR) {
                    $this->output->write('<error>'.$buffer.'</error>');
                } else {
                    $this->output->write($buffer);
                }
            });

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            return true;
        } catch (\Exception $e) {
            $this->output->error(sprintf(
                'Failed to install dependencies for plugin %s: %s',
                $plugin['name'],
                $e->getMessage()
            ));

            if (isset($process)) {
                $this->output->writeln('<comment>Command output:</comment>');
                $this->output->writeln($process->getOutput());
                $this->output->writeln('<comment>Error output:</comment>');
                $this->output->writeln($process->getErrorOutput());
            }

            return false;
        }
    }

    /**
     * Check if a plugin has a composer.json file
     */
    public function hasComposerDependencies(string $pluginName): bool
    {
        return file_exists($this->pluginsPath.'/'.$pluginName.'/composer.json');
    }

    /**
     * Enable a plugin by its ID
     */
    public function enablePlugin(string $pluginId): bool
    {
        $plugin = $this->findPlugin($pluginId);
        if (! $plugin) {
            return false;
        }

        $manifestPath = $plugin['path'].'/plugin.json';
        $manifest = json_decode(file_get_contents($manifestPath), true);
        $manifest['enabled'] = true;

        file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->plugins = [];

        return true;
    }

    public function disablePlugin(string $pluginId): bool
    {
        $plugin = $this->findPlugin($pluginId);
        if (! $plugin) {
            return false;
        }

        $manifestPath = $plugin['path'].'/plugin.json';
        $manifest = json_decode(file_get_contents($manifestPath), true);
        $manifest['enabled'] = false;

        file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->plugins = [];

        if (function_exists('app') && app() instanceof \Illuminate\Contracts\Foundation\Application) {
            if (app()->routesAreCached()) {
                app()->forgetRouteCache();
            }

            $router = app('router');

            // Clear the router's routes to ensure disabled plugins' routes are removed
            $reflection = new \ReflectionProperty($router, 'routes');
            $reflection->setAccessible(true);
            $reflection->setValue($router, new \Illuminate\Routing\RouteCollection);

            $compiledPath = app()->bootstrapPath('cache/routes-v7.php');
            if (file_exists($compiledPath)) {
                unlink($compiledPath);
            }

            $this->app->boot();
        }

        return true;
    }

    /**
     * Check if a plugin is enabled by its ID
     */
    public function isPluginEnabled(string $pluginId): bool
    {
        $plugin = $this->findPlugin($pluginId);
        if (! $plugin) {
            return false;
        }

        $manifestPath = $plugin['path'].'/plugin.json';
        if (! file_exists($manifestPath)) {
            return false;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);

        return $manifest['enabled'] ?? true;
    }

    public function registerPlugins(): void
    {
        $this->discover()->each(function ($plugin) {
            if ($this->isPluginEnabled($plugin['id'])) {
                try {
                    $this->app->register($plugin['provider']);
                    Log::debug("Registered plugin service provider: {$plugin['provider']}");
                } catch (\Exception $e) {
                    Log::error("Failed to register plugin {$plugin['id']}: ".$e->getMessage());
                }
            } else {
                Log::debug("Skipping disabled plugin: {$plugin['id']}");
            }
        });
    }
}
