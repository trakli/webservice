<?php

namespace Tests\Feature\Plugin;

use Tests\TestCase;

class PluginCommandsTest extends TestCase
{
    protected string $pluginsPath;

    protected string $examplePluginPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pluginsPath = base_path('plugins');
        $this->examplePluginPath = "{$this->pluginsPath}/example";
        $this->app->instance('path.plugins', $this->pluginsPath);
        $this->resetExamplePluginState();
    }

    protected function tearDown(): void
    {
        // Reset any modified plugin state
        $this->resetExamplePluginState();
        parent::tearDown();
    }

    protected function resetExamplePluginState(): void
    {
        $manifestPath = "{$this->examplePluginPath}/plugin.json";
        if (file_exists($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            if (($manifest['enabled'] ?? true) !== true) {
                $manifest['enabled'] = true;
                file_put_contents(
                    $manifestPath,
                    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );
            }
        }
    }

    /** @test */
    public function it_lists_plugins()
    {
        $this->artisan('plugin:list')
            ->assertExitCode(0)
            ->expectsOutputToContain('Example Plugin');
    }

    /** @test */
    public function it_shows_plugin_info()
    {
        $this->artisan('plugin:info example')
            ->assertExitCode(0)
            ->expectsOutputToContain('Example Plugin');
    }

    /** @test */
    public function it_enables_plugins()
    {
        // First disable the plugin
        $this->artisan('plugin:disable example')
            ->assertExitCode(0);

        // Then enable it
        $this->artisan('plugin:enable example')
            ->assertExitCode(0);

        $this->artisan('plugin:info example')
            ->expectsOutputToContain('Enabled')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_disables_plugins()
    {
        $this->artisan('plugin:disable example')
            ->assertExitCode(0)
            ->expectsOutputToContain('disabled successfully');
    }

    /** @test */
    public function it_handles_nonexistent_plugin()
    {
        $this->artisan('plugin:info nonexistent')
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_suggests_similar_plugin_names()
    {
        $this->artisan('plugin:info examp')
            ->expectsOutputToContain('Did you mean one of these?')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_prevents_access_to_disabled_plugin_routes_after_disable_command()
    {
        // First, ensure the plugin is enabled
        $this->artisan('plugin:enable example')
            ->assertExitCode(0);

        // Test that the route is accessible when enabled
        $response = $this->get('/api/example');
        $response->assertStatus(200);

        // Disable the plugin using the command
        $this->artisan('plugin:disable example')
            ->assertExitCode(0);

        // Clear the application state
        $this->app->forgetInstance('router');
        $this->app->forgetInstance('routes');

        // Re-register service providers
        $this->app->registerConfiguredProviders();

        // Get a fresh instance of the router
        $router = $this->app->make('router');
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();

        // Test that the route returns 404 when disabled
        $response = $this->get('/api/example');
        $response->assertStatus(404);
    }
}
