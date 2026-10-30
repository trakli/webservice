<?php

namespace App\Ai\Tools\Read;

use App\Services\WorkspaceFinance;
use Whilesmart\Agents\Enums\ToolPermission;
use Whilesmart\Agents\Tools\AbstractTool;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

class ListWorkspaceFinancesTool extends AbstractTool
{
    public function name(): string
    {
        return 'list_workspace_finances';
    }

    public function description(): string
    {
        return 'List the shared groups you belong to. Supply a workspace slug to read its shared budgets, wallets,' .
            ' labels, and up to 50 shared transactions. Personal records are excluded unless explicitly shared.';
    }

    public function permission(): ToolPermission
    {
        return ToolPermission::READ;
    }

    public function parameters(): array
    {
        return [ParameterSpec::string('workspace', 'The exact workspace slug returned by this tool.', required: false)];
    }

    public function handle(array $arguments, ToolContext $context): array
    {
        $user = $context->user;
        if (! $user) {
            return ['error' => 'Sign in to view shared finances.'];
        }
        $finance = app(WorkspaceFinance::class);
        if (empty($arguments['workspace'])) {
            return ['workspaces' => $user->workspaces()->where('is_active', true)->distinct()->get()
                ->map(fn ($workspace) => ['name' => $workspace->name, 'slug' => $workspace->slug,
                    'role' => $finance->role($workspace, $user)])->all()];
        }
        $workspace = $user->workspaces()->where('is_active', true)->where('slug', $arguments['workspace'])->first();
        if (! $workspace) {
            return ['error' => 'Shared space not found or no longer accessible.'];
        }
        $finance->authorize($workspace, $user);
        $transactions = $finance->transactions($workspace)->latest('datetime')->limit(50)->get();

        return ['workspace' => $workspace->name, 'read_only' => ! $finance->eligible($workspace),
            'budgets' => $workspace->budgets()->get()->map(fn ($budget) => [
                'id' => $budget->id, 'name' => $budget->name, 'currency' => $budget->currency, 'progress' => $budget->progress,
            ])->all(),
            'wallets' => $finance->wallets($workspace)->get(['id', 'name', 'currency', 'balance'])->makeHidden(['last_synced_at',
                'client_generated_id', 'icon', 'stats'])->toArray(),
            'categories' => \App\Models\Category::where('workspace_id', $workspace->id)->get(['id', 'name'])->setHidden(['last_synced_at',
                'client_generated_id', 'icon'])->toArray(),
            'groups' => \App\Models\Group::where('workspace_id', $workspace->id)->get(['id', 'name'])->setHidden(['last_synced_at',
                'client_generated_id', 'icon'])->toArray(),
            'transactions' => $finance->presentTransactions($workspace, $transactions, $user)->all()];
    }
}
