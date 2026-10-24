<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Services\WorkspaceFinance;
use Closure;
use Illuminate\Http\Request;

class WorkspaceAccess
{
    public function handle(Request $request, Closure $next)
    {
        $workspace = $request->route('workspace');
        if ($workspace instanceof Workspace) {
            $finance = app(WorkspaceFinance::class);
            $finance->authorize($workspace, $request->user());
            $action = $request->route()->getActionMethod();
            $cleanup = ['leave', 'removeMember', 'cancelInvitation', 'destroy', 'unshareWallet', 'unshareTransaction'];
            if (! $request->isMethodSafe() && ! in_array($action, $cleanup, true)) {
                $finance->requireEligible($workspace);
            }
        }

        return $next($request);
    }
}
