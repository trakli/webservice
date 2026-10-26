<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Jobs\CloseBudgetPeriodJob;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Group;
use App\Models\Workspace;
use App\Services\BudgetProgressService;
use App\Services\WorkspaceFinance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkspaceBudgetController extends ApiController
{
    public function __construct(private WorkspaceFinance $finance)
    {
    }

    public function storeBudget(Request $request, Workspace $workspace): JsonResponse
    {
        $this->finance->authorize($workspace, $request->user(), manage: true, write: true);
        $data = $this->budgetData($request, $workspace);
        $budget = DB::transaction(function () use ($workspace, $data) {
            $budget = $workspace->budgets()->create(collect($data)->except('targets')->all());
            $this->setTargets($budget, $data['targets'] ?? []);
            return $budget;
        });

        return $this->success($budget->fresh(), 'Shared budget created', 201);
    }

    public function updateBudget(Request $request, Workspace $workspace, int $budgetId): JsonResponse
    {
        $this->finance->authorize($workspace, $request->user(), manage: true, write: true);
        $budget = $workspace->budgets()->findOrFail($budgetId);
        $data = $this->budgetData($request, $workspace);
        DB::transaction(function () use ($budget, $data) {
            $budget->fill(collect($data)->except('targets')->all())->save();
            $this->setTargets($budget, $data['targets'] ?? []);
        });
        $this->finance->refresh($workspace);

        return $this->success($budget->fresh(), 'Shared budget updated');
    }

    public function deleteBudget(Request $request, Workspace $workspace, int $budgetId): JsonResponse
    {
        $this->finance->authorize($workspace, $request->user(), manage: true, write: true);
        $workspace->budgets()->findOrFail($budgetId)->delete();

        return $this->success(null, 'Budget deleted');
    }

    public function budgetTransactions(Request $request, Workspace $workspace, int $budgetId): JsonResponse
    {
        $budget = $workspace->budgets()->findOrFail($budgetId);
        [$start, $end] = app(BudgetProgressService::class)->resolvePeriodWindow($budget);
        $items = $this->finance->budgetTransactions($budget)->whereBetween('datetime', [$start, $end])->whereNull('transfer_id')
            ->latest('datetime')->paginate(25);

        return $this->success(['data' => collect($items->items())->map(fn ($transaction) => $this->finance->presentTransaction(
            $workspace,
            $transaction,
            $request->user()
        )),
            'current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'per_page' => 25, 'total' => $items->total()]);
    }

    public function budgetPeriods(Workspace $workspace, int $budgetId): JsonResponse
    {
        $budget = $workspace->budgets()->findOrFail($budgetId);
        $states = $budget->periodStates()->whereNotNull('closed_at')->latest('period_start')->paginate(25);
        return $this->success(['data' => collect($states->items())->map(fn ($state) => $state->only([
            'id', 'period_start', 'period_end', 'net_spent', 'rollover_in', 'rollover_out', 'closed_at',
        ])), 'current_page' => $states->currentPage(), 'last_page' => $states->lastPage(),
            'per_page' => $states->perPage(), 'total' => $states->total()]);
    }

    public function closeBudget(Request $request, Workspace $workspace, int $budgetId): JsonResponse
    {
        $this->finance->authorize($workspace, $request->user(), manage: true, write: true);
        $budget = $workspace->budgets()->findOrFail($budgetId);
        if (! $budget->rollover_enabled) {
            $this->finance->deny('Rollover is not enabled for this budget.');
        }
        [, $end] = app(BudgetProgressService::class)->resolvePeriodWindow($budget, \Carbon\CarbonImmutable::now()->subDay());
        if ($end->isFuture()) {
            $this->finance->deny('This period is still open. It closes after its end date.');
        }
        CloseBudgetPeriodJob::dispatchSync($budget->id);

        return $this->success($budget->fresh(), 'Budget period closed');
    }

    private function budgetData(Request $request, Workspace $workspace): array
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'description' => 'nullable|string|max:1000',
            'amount' => 'required|numeric|min:0', 'currency' => 'required|string|size:3|regex:/^[A-Z]{3}$/',
            'period_type' => 'required|in:weekly,monthly,yearly,custom', 'start_date' => 'required|date',
            'end_date' => 'required_if:period_type,custom|nullable|date|after_or_equal:start_date',
            'rollover_enabled' => 'sometimes|boolean', 'threshold_percent' => 'sometimes|integer|between:0,100',
            'forecast_alerts_enabled' => 'sometimes|boolean', 'is_active' => 'sometimes|boolean',
            'targets' => 'sometimes|array', 'targets.*.type' => 'required|in:category,group,wallet', 'targets.*.id' => 'required|integer']);
        foreach ($data['targets'] ?? [] as $target) {
            $query = match ($target['type']) {
                'category' => Category::where('workspace_id', $workspace->id),
                'group' => Group::where('workspace_id', $workspace->id),
                'wallet' => $this->finance->wallets($workspace),
            };
            if (! $query->whereKey($target['id'])->exists()) {
                $this->finance->deny('Choose budget targets available in this workspace.');
            }
        }
        return $data;
    }

    private function setTargets(Budget $budget, array $targets): void
    {
        foreach (['category' => 'categories', 'group' => 'groups', 'wallet' => 'wallets'] as $kind => $relation) {
            $budget->{$relation}()->sync(collect($targets)->where('type', $kind)->pluck('id')->all());
        }
        $budget->touch();
    }
}
