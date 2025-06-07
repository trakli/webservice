<?php

namespace Tests\Feature\Plugin;

use App\Services\PluginManager;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PluginManagerTest extends TestCase
{
    protected string $pluginsPath;

    protected PluginManager $pluginManager;

    protected string $examplePluginPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pluginsPath = base_path('plugins');
        $this->examplePluginPath = "{$this->pluginsPath}/example";
        $this->app->instance('path.plugins', $this->pluginsPath);
        $this->pluginManager = new PluginManager($this->app);
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

    protected function createTestPlugin(string $pluginId, array $manifest = []): string
    {
        $pluginPath = "{$this->pluginsPath}/{$pluginId}";

        File::ensureDirectoryExists("{$pluginPath}/src/Http/Controllers");
        File::ensureDirectoryExists("{$pluginPath}/resources/views");
        File::ensureDirectoryExists("{$pluginPath}/routes");

        $defaultManifest = [
            'id' => $pluginId,
            'name' => 'Test Plugin '.ucfirst($pluginId),
            'description' => 'Test plugin description',
            'version' => '1.0.0',
            'namespace' => 'Trakli\\'.ucfirst($pluginId).'Plugin',
            'provider' => 'Trakli\\'.ucfirst($pluginId).'Plugin\\'.ucfirst($pluginId).'ServiceProvider',
            'enabled' => true,
        ];

        $manifest = array_merge($defaultManifest, $manifest);

        File::put(
            "{$pluginPath}/plugin.json",
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $providerPath = "{$pluginPath}/src/".ucfirst($pluginId).'ServiceProvider.php';
        $providerClass = ucfirst($pluginId).'ServiceProvider';
        $providerNamespace = $manifest['namespace'];

        File::put($providerPath, <<<EOT
<?php

namespace {$providerNamespace};

use Illuminate\Support\ServiceProvider;

class {$providerClass} extends ServiceProvider
{
    public function register() {}
    public function boot() {}
}
EOT
        );

        return $pluginPath;
    }

    /** @test */
    public function it_can_discover_plugins()
    {
        $plugins = $this->pluginManager->discover();

        // Should find at least the example plugin
        $this->assertGreaterThanOrEqual(1, $plugins->count());
        $this->assertNotNull($plugins->firstWhere('id', 'example'));
    }

    /** @test */
    public function it_can_find_a_plugin_by_id_case_insensitive()
    {
        $plugin = $this->pluginManager->findPlugin('EXAMPLE');

        $this->assertNotNull($plugin);
        $this->assertEquals('example', $plugin['id']);
    }

    /** @test */
    public function it_returns_null_for_nonexistent_plugin()
    {
        $plugin = $this->pluginManager->findPlugin('nonexistent');

        $this->assertNull($plugin);
    }

    /** @test */
    public function it_validates_plugin_manifest()
    {
        $tempPath = storage_path('framework/testing/temp_plugin');
        File::ensureDirectoryExists($tempPath);

        File::put(
            "{$tempPath}/plugin.json",
            json_encode(['name' => 'Invalid Plugin'])
        );

        $originalPath = $this->pluginsPath;
        $this->app->instance('path.plugins', dirname($tempPath));
        $this->pluginManager = new PluginManager($this->app);

        $plugins = $this->pluginManager->discover();

        $this->assertEmpty($plugins->where('path', $tempPath));

        File::deleteDirectory($tempPath);
        $this->app->instance('path.plugins', $originalPath);
        $this->pluginManager = new PluginManager($this->app);
    }

    /** @test */
    public function it_can_enable_and_disable_plugins()
    {
        $this->assertTrue($this->pluginManager->isPluginEnabled('example'));

        $this->assertTrue($this->pluginManager->disablePlugin('example'));
        $this->assertFalse($this->pluginManager->isPluginEnabled('example'));

        $this->assertTrue($this->pluginManager->enablePlugin('example'));
        $this->assertTrue($this->pluginManager->isPluginEnabled('example'));
    }

    /** @test */
    public function it_requires_plugin_id_to_match_directory_name()
    {
        $tempPath = storage_path('framework/testing/mismatched_plugin');
        File::ensureDirectoryExists($tempPath);

        File::put(
            "{$tempPath}/plugin.json",
            json_encode([
                'id' => 'differentid',
                'name' => 'Mismatched Plugin',
                'description' => 'Test plugin with mismatched ID',
                'version' => '1.0.0',
                'namespace' => 'Trakli\MismatchedPlugin',
                'provider' => 'Trakli\MismatchedPlugin\MismatchedPluginServiceProvider',
                'enabled' => true,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $originalPath = $this->pluginsPath;
        $this->app->instance('path.plugins', dirname($tempPath));
        $this->pluginManager = new PluginManager($this->app);

        $plugins = $this->pluginManager->discover();

        $this->assertEmpty($plugins->where('path', $tempPath));

        File::deleteDirectory($tempPath);
        $this->app->instance('path.plugins', $originalPath);
        $this->pluginManager = new PluginManager($this->app);
    }

    /** @test */
    public function it_allows_access_to_protected_route_when_authenticated()
    {
        $this->pluginManager->enablePlugin('example');

        $user = \App\Models\User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->get('/api/example/protected');

        $response->assertStatus(401);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->get('/api/example/protected');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'user_id',
            'user_name',
            'timestamp',
        ]);
        $response->assertJson([
            'user_id' => $user->id,
            'user_name' => $user->name,
        ]);
    }

    /** @test */
    public function it_prevents_access_to_disabled_plugin_routes()
    {
        $pluginManager = app(PluginManager::class);

        $pluginManager->enablePlugin('example');

        $response = $this->get('/api/example');
        $response->assertStatus(200);

        $pluginManager->disablePlugin('example');

        $response = $this->get('/api/example');
        $response->assertStatus(404);
    }
}
