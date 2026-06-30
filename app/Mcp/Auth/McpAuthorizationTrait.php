<?php

declare(strict_types=1);

namespace App\Mcp\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Authorization trait for MCP tools and resources.
 *
 * Provides convenient methods for checking permissions within
 * tool/resource handle() and read() methods.
 */
trait McpAuthorizationTrait
{
    /**
     * Authorize the current user against an MCP permission.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    protected function authorizeMcp(string $permission): void
    {
        $user = $this->getMcpUser();

        if (! McpGateRegistrar::allows($user, $permission)) {
            abort(403, "MCP permission denied: {$permission}");
        }
    }

    /**
     * Check if the current user has a permission.
     */
    protected function canMcp(string $permission): bool
    {
        $user = $this->getMcpUser();

        return McpGateRegistrar::allows($user, $permission);
    }

    /**
     * Check if the current user has all of the given permissions.
     *
     * @param array<string> $permissions
     */
    protected function canAllMcp(array $permissions): bool
    {
        $user = $this->getMcpUser();

        return McpGateRegistrar::allowsAll($user, $permissions);
    }

    /**
     * Check if the current user has any of the given permissions.
     *
     * @param array<string> $permissions
     */
    protected function canAnyMcp(array $permissions): bool
    {
        $user = $this->getMcpUser();

        return McpGateRegistrar::allowsAny($user, $permissions);
    }

    /**
     * Get the currently authenticated user.
     */
    private function getMcpUser(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(401, 'Authentication required');
        }

        return $user;
    }
}
