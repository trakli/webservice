<?php

namespace App\Ai\Tools\Write;

use InvalidArgumentException;
use App\Models\Workspace;
use App\Models\Wallet;
use Whilesmart\Entitlements\Contracts\Entitlements;
use App\Services\WorkspaceFinance;
use Illuminate\Support\Facades\Validator;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

class ProposeWorkspaceChangeTool extends AbstractWriteTool
{
    public function name(): string
    {
        return 'propose_workspace_change';
    }

    public function actionType(): string
    {
        return 'workspace.change';
    }

    public function description(): string
    {
        return 'Propose creating a family/group, a shared budget, or a transaction in a workspace-owned wallet. Requires' .
            ' confirmation. Always identify the workspace explicitly before proposing a shared financial change.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::enum('operation', 'What to propose.', ['create_workspace', 'create_budget', 'record_transaction']),
            ParameterSpec::string(
                'workspace',
                'Exact workspace slug from list_workspace_finances. Required except when creating a workspace.',
                required: false
            ),
            ParameterSpec::string('name', 'Workspace or budget name.', required: false),
            ParameterSpec::enum('kind', 'Workspace kind.', ['family', 'group'], required: false),
            ParameterSpec::number('amount', 'Budget limit or transaction amount.', required: false),
            ParameterSpec::string('currency', 'Three-letter uppercase budget currency.', required: false),
            ParameterSpec::enum('period_type', 'Budget period.', ['weekly', 'monthly', 'yearly', 'custom'], required: false),
            ParameterSpec::string('start_date', 'Budget start date, YYYY-MM-DD.', required: false),
            ParameterSpec::string('end_date', 'Required for a custom budget period, YYYY-MM-DD.', required: false),
            ParameterSpec::number('wallet_id', 'Workspace-owned wallet id from list_workspace_finances.', required: false),
            ParameterSpec::enum('type', 'Transaction direction.', ['income', 'expense'], required: false),
            ParameterSpec::string('datetime', 'Transaction time in ISO 8601.', required: false),
            ParameterSpec::boolean('is_refund', 'Mark an incoming transaction as a refund.', required: false),
            ParameterSpec::arrayOfObject('targets', 'Optional workspace budget targets.', [
                ParameterSpec::enum('type', 'Target kind.', ['category', 'group', 'wallet']),
                ParameterSpec::number('id', 'Target id from list_workspace_finances.'),
            ], required: false),
            ParameterSpec::string('description', 'What this is for.', required: false),
        ];
    }

    protected function buildPayload(array $arguments, ToolContext $context): array
    {
        $operation = $arguments['operation'] ?? '';
        $data = Validator::make($arguments, ['operation' => 'required|in:create_workspace,create_budget,record_transaction',
            'workspace' => 'required_unless:operation,create_workspace|string',
            'name' => 'required_if:operation,create_workspace,create_budget|string|max:255',
            'kind' => 'required_if:operation,create_workspace|in:family,group',
            'amount' => 'required_if:operation,create_budget,record_transaction|numeric|gt:0',
            'currency' => 'required_if:operation,create_budget|string|regex:/^[A-Z]{3}$/',
            'period_type' => 'required_if:operation,create_budget|in:weekly,monthly,yearly,custom',
            'start_date' => 'required_if:operation,create_budget|date', 'end_date' => 'required_if:period_type,custom|date|after_or_equal:start_date',
            'wallet_id' => 'required_if:operation,record_transaction|integer', 'type' => 'required_if:operation,record_transaction|in:income,expense',
            'datetime' => 'required_if:operation,record_transaction|date', 'is_refund' => 'sometimes|boolean',
            'targets' => 'sometimes|array', 'targets.*.type' => 'required|in:category,group,wallet', 'targets.*.id' => 'required|integer',
                'description' => 'sometimes|string|max:2000'])->validate();
        if ($operation !== 'create_workspace') {
            $workspace = Workspace::where('slug', $data['workspace'])->firstOrFail();
            app(WorkspaceFinance::class)->authorize($workspace, $context->user, manage: $operation === 'create_budget', write: true);
            if ($operation === 'record_transaction' && ! Wallet::where('workspace_id', $workspace->id)->whereKey($data['wallet_id'])->exists()) {
                throw new InvalidArgumentException('Choose a wallet owned by this workspace.');
            }
        } elseif (! app(Entitlements::class)->allows($context->user, 'shared_workspaces')) {
            throw new InvalidArgumentException('You cannot create shared workspaces.');
        }

        return $data;
    }

    protected function summarize(array $payload, ToolContext $context): string
    {
        return match ($payload['operation']) {
            'create_workspace' => 'Create ' . $payload['kind'] . ' space: ' . $payload['name'],
            'create_budget' => 'Create shared budget: ' . $payload['name'] . ' (' . $payload['workspace'] . ')',
            default => 'Record ' . $payload['type'] . ' of ' . $payload['amount'] . ' in ' . $payload['workspace'],
        };
    }
}
