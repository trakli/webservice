<?php

declare(strict_types=1);

namespace App\Mcp\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Registers Laravel Gates from the MCP permissions configuration.
 *
 * Each permission defined in config/mcp.php is registered as a Gate,
 * allowing fine-grained authorization checks on MCP operations.
 *
 * Permissions can be configured via closure or by referencing a Policy.
 */
final class McpGateRegistrar
{
    /**
     * Register all MCP permission Gates.
     */
    public static function register(): void
    {
        $permissions = config('mcp.permissions', []);
        $customGates = config('mcp.auth.permission_gates', []);

        foreach ($permissions as $permission => $config) {
            $gateName = $config['gate'] ?? $permission;

            // Skip if a custom gate definition already exists
            if (isset($customGates[$permission])) {
                Gate::define($permission, $customGates[$permission]);

                continue;
            }

            // Register a default gate that allows all authenticated users.
            // When a gate name is configured (e.g., 'transactions.view' for
            // permission 'transactions.read'), the closure delegates to
            // that gate so operators can define policies independently.
            Gate::define($permission, function (User $user) use ($gateName, $permission) {
                if ($gateName === $permission) {
                    return true;
                }

                if (! Gate::has($gateName)) {
                    return false;
                }

                return Gate::forUser($user)->allows($gateName);
            });
        }
    }

    /**
     * Register permission Gates from a plugin's metadata.
     *
     * @param array<string> $permissions
     * @param callable|null $checker Custom authorization checker (User, string permission → bool)
     */
    public static function registerPluginPermissions(array $permissions, ?callable $checker = null): void
    {
        $checker ??= function (User $user, string $permission): bool {
            return Gate::forUser($user)->allows($permission);
        };

        foreach ($permissions as $permission) {
            if (! Gate::has($permission)) {
                Gate::define($permission, $checker);
            }
        }
    }

    /**
     * Check if a user has the given MCP permission.
     */
    public static function allows(User $user, string $permission): bool
    {
        return Gate::forUser($user)->allows($permission);
    }

    /**
     * Check if a user has all the given MCP permissions.
     *
     * @param array<string> $permissions
     */
    public static function allowsAll(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! static::allows($user, $permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a user has any of the given MCP permissions.
     *
     * @param array<string> $permissions
     */
    public static function allowsAny(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (static::allows($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the list of registered MCP permission names.
     *
     * @return array<string>
     */
    public static function getRegisteredPermissions(): array
    {
        return array_keys(config('mcp.permissions', []));
    }
}
