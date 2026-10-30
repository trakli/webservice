<?php

namespace App\Http\Controllers\API\v1;

use App\Models\Workspace;
use App\Services\WorkspaceFinance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Whilesmart\Entitlements\Contracts\Entitlements;
use Whilesmart\Workspaces\Http\Controllers\WorkspaceController as BaseController;
use Whilesmart\Workspaces\Models\Workspace as BaseWorkspace;

class WorkspaceController extends BaseController
{
    public function index(): JsonResponse
    {
        $user = auth()->user();
        $workspaces = $user->workspaces()->where('is_active', true)->where('is_personal', false)->distinct()->get();
        $items = $workspaces->map(fn ($workspace) => $this->present($workspace))->values()->all();

        return $this->respond(['data' => ['data' => $items, 'current_page' => 1, 'last_page' => 1,
            'per_page' => count($items), 'total' => count($items),
            'can_create' => app(Entitlements::class)->allows($user, 'shared_workspaces')]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500']);
        if (! app(Entitlements::class)->allows($request->user(), 'shared_workspaces')) {
            app(WorkspaceFinance::class)->deny('You cannot create shared workspaces.', 403);
        }
        $workspace = DB::transaction(fn () => $request->user()->createWorkspace($data['name'], [
            'type' => 'team', 'description' => $data['description'] ?? null,
        ]));

        return $this->respond(['data' => $this->present($workspace)], 201);
    }

    public function show(BaseWorkspace $workspace): JsonResponse
    {
        app(WorkspaceFinance::class)->authorize($workspace, auth()->user());

        return $this->respond(['data' => $this->present($workspace)]);
    }

    public function destroy(BaseWorkspace $workspace): JsonResponse
    {
        return DB::transaction(function () use ($workspace) {
            $response = parent::destroy($workspace);
            if ($response->isSuccessful()) {
                $workspace->budgets()->update(['is_active' => false]);
            }
            return $response;
        });
    }

    public function removeMember(BaseWorkspace $workspace, string $userId): JsonResponse
    {
        return DB::transaction(function () use ($workspace, $userId) {
            $response = parent::removeMember($workspace, $userId);
            if ($response->isSuccessful()) {
                app(WorkspaceFinance::class)->revokeMember($workspace, (int) $userId);
            }
            return $response;
        });
    }

    public function leave(BaseWorkspace $workspace): JsonResponse
    {
        return DB::transaction(function () use ($workspace) {
            $response = parent::leave($workspace);
            if ($response->isSuccessful()) {
                app(WorkspaceFinance::class)->revokeMember($workspace, (int) auth()->id());
            }
            return $response;
        });
    }

    private function present(Workspace $workspace): array
    {
        $finance = app(WorkspaceFinance::class);
        $role = $finance->role($workspace, auth()->user());

        return ['id' => $workspace->id, 'slug' => $workspace->slug, 'name' => $workspace->name,
            'description' => $workspace->description,
            'role' => $role, 'read_only' => ! $finance->eligible($workspace),
            'can_manage' => in_array($role, ['owner', 'admin'], true),
            'member_count' => $workspace->members()->distinct()->count('users.id')];
    }
}
