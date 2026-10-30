<?php

namespace App\Workspaces;

use App\Services\WorkspaceFinance;
use Illuminate\Contracts\Auth\Authenticatable;
use Whilesmart\Workspaces\Contracts\InvitationGuard as Guard;
use Whilesmart\Workspaces\Models\Workspace;
use Whilesmart\Workspaces\Models\WorkspaceInvitation;

/** @SuppressWarnings(PHPMD.UnusedFormalParameter) */
class InvitationGuard implements Guard
{
    public function beforeInvite(Workspace $workspace, string $email, string $role, ?Authenticatable $inviter): void
    {
        app(WorkspaceFinance::class)->requireEligible(\App\Models\Workspace::findOrFail($workspace->id));
    }

    public function beforeAccept(WorkspaceInvitation $invitation, Authenticatable $user): void
    {
        app(WorkspaceFinance::class)->requireEligible(\App\Models\Workspace::findOrFail($invitation->workspace_id));
    }
}
