<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Auth\McpGateRegistrar;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class McpGateRegistrarTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mcp.permissions', [
            'transactions.read' => [
                'description' => 'Read access to transactions',
                'gate' => 'transactions.view',
            ],
            'transactions.write' => [
                'description' => 'Create/update/delete transactions',
                'gate' => 'transactions.manage',
            ],
            'budgets.read' => [
                'description' => 'Read access to budgets',
                'gate' => 'budgets.view',
            ],
            'wallets.read' => [
                'description' => 'Read access to wallets',
                'gate' => 'wallets.view',
            ],
            'reports.read' => [
                'description' => 'Read access to financial reports',
                'gate' => 'reports.view',
            ],
        ]);
    }

    public function test_register_creates_gates_for_all_permissions()
    {
        Gate::define('transactions.view', fn (User $user) => $user->email === 'admin@test.com');
        Gate::define('budgets.view', fn (User $user) => true);

        McpGateRegistrar::register();

        $this->assertTrue(Gate::has('transactions.read'));
        $this->assertTrue(Gate::has('transactions.write'));
        $this->assertTrue(Gate::has('budgets.read'));
        $this->assertTrue(Gate::has('wallets.read'));
    }

    public function test_allows_checks_permission_against_gate()
    {
        $admin = User::factory()->create(['email' => 'admin@test.com']);
        $user = User::factory()->create(['email' => 'user@test.com']);

        Gate::define('transactions.view', fn (User $u) => $u->email === 'admin@test.com');
        McpGateRegistrar::register();

        $this->assertTrue(McpGateRegistrar::allows($admin, 'transactions.read'));
        $this->assertFalse(McpGateRegistrar::allows($user, 'transactions.read'));
    }

    public function test_allows_returns_true_when_gate_allows()
    {
        $user = User::factory()->create();

        Gate::define('transactions.view', fn () => true);
        McpGateRegistrar::register();

        $this->assertTrue(McpGateRegistrar::allows($user, 'transactions.read'));
    }

    public function test_allows_returns_false_when_gate_denies()
    {
        $user = User::factory()->create();

        Gate::define('transactions.view', fn () => false);
        McpGateRegistrar::register();

        $this->assertFalse(McpGateRegistrar::allows($user, 'transactions.read'));
    }

    public function test_allows_all_checks_multiple_permissions()
    {
        $user = User::factory()->create();

        Gate::define('transactions.view', fn () => true);
        Gate::define('budgets.view', fn () => true);
        McpGateRegistrar::register();

        $this->assertTrue(McpGateRegistrar::allowsAll($user, ['transactions.read', 'budgets.read']));
        $this->assertFalse(McpGateRegistrar::allowsAll($user, ['transactions.read', 'nonexistent']));
    }

    public function test_allows_any_checks_any_permission()
    {
        $user = User::factory()->create();

        Gate::define('transactions.view', fn () => false);
        Gate::define('budgets.view', fn () => true);
        McpGateRegistrar::register();

        $this->assertTrue(McpGateRegistrar::allowsAny($user, ['transactions.read', 'budgets.read']));
        $this->assertFalse(McpGateRegistrar::allowsAny($user, ['transactions.read', 'wallets.read']));
    }

    public function test_register_plugin_permissions_creates_missing_gates()
    {
        $user = User::factory()->create();

        $this->assertFalse(Gate::has('custom.plugin_perm'));

        McpGateRegistrar::registerPluginPermissions(
            ['custom.plugin_perm'],
            fn (User $u) => $u->email === 'plugin@test.com',
        );

        $this->assertTrue(Gate::has('custom.plugin_perm'));

        $pluginUser = User::factory()->create(['email' => 'plugin@test.com']);
        $this->assertTrue(McpGateRegistrar::allows($pluginUser, 'custom.plugin_perm'));
    }

    public function test_register_plugin_permissions_skips_existing_gates()
    {
        $user = User::factory()->create();

        Gate::define('custom.existing', fn () => true);
        McpGateRegistrar::registerPluginPermissions(
            ['custom.existing'],
            fn (User $u) => false,
        );

        // Should still allow because existing gate takes precedence
        $this->assertTrue(McpGateRegistrar::allows($user, 'custom.existing'));
    }

    public function test_register_plugin_permissions_uses_default_checker()
    {
        $user = User::factory()->create();

        Gate::define('custom.default', fn () => true);
        McpGateRegistrar::registerPluginPermissions(['custom.default']);

        $this->assertTrue(McpGateRegistrar::allows($user, 'custom.default'));
    }

    public function test_get_registered_permissions_returns_permission_names()
    {
        $permissions = McpGateRegistrar::getRegisteredPermissions();

        $this->assertContains('transactions.read', $permissions);
        $this->assertContains('budgets.read', $permissions);
        $this->assertContains('wallets.read', $permissions);
        $this->assertNotContains('nonexistent', $permissions);
    }

    public function test_custom_permission_gates_override_defaults()
    {
        $user = User::factory()->create();

        Config::set('mcp.auth.permission_gates', [
            'transactions.read' => fn (User $u) => $u->email === 'custom@test.com',
        ]);

        McpGateRegistrar::register();

        $customUser = User::factory()->create(['email' => 'custom@test.com']);
        $this->assertTrue(McpGateRegistrar::allows($customUser, 'transactions.read'));
        $this->assertFalse(McpGateRegistrar::allows($user, 'transactions.read'));
    }
}