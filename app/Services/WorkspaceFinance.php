<?php

namespace App\Services;

use App\Jobs\RecomputeBudgetProgressJob;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Whilesmart\Entitlements\Contracts\Entitlements;

class WorkspaceFinance
{
    public function role(Workspace $workspace, User $user): ?string
    {
        if (! $workspace->is_active) {
            return null;
        }
        foreach (['owner', 'admin', 'member'] as $role) {
            if ($user->hasRole($role, $workspace->getMorphClass(), $workspace->id)) {
                return $role;
            }
        }

        return null;
    }

    public function authorize(Workspace $workspace, User $user, bool $manage = false, bool $write = false): void
    {
        $role = $this->role($workspace, $user);
        if (! $role || ($manage && ! in_array($role, ['owner', 'admin'], true))) {
            $this->deny('You do not have permission to access this workspace.', 403);
        }
        if ($write) {
            $this->requireEligible($workspace);
        }
    }

    public function eligible(Workspace $workspace): bool
    {
        return app(Entitlements::class)->allows($workspace, 'shared_workspaces');
    }

    public function requireEligible(Workspace $workspace): void
    {
        if (! $this->eligible($workspace)) {
            $this->deny('Changes to this workspace are not allowed.', 403);
        }
    }

    public function wallets(Workspace $workspace): Builder
    {
        return Wallet::query()->where(function (Builder $query) use ($workspace) {
            $query->where('wallets.workspace_id', $workspace->id)
                ->orWhereExists(function ($shares) use ($workspace) {
                    $shares->selectRaw('1')->from('workspace_wallet_shares')
                        ->where('workspace_id', $workspace->id)
                        ->whereColumn('wallet_id', 'wallets.id');
                });
        });
    }

    public function transactions(Workspace $workspace): Builder
    {
        return Transaction::query()->where(function (Builder $query) use ($workspace) {
            $query->whereIn('wallet_id', $this->wallets($workspace)->select('wallets.id'))
                ->orWhereExists(function ($shares) use ($workspace) {
                    $shares->selectRaw('1')->from('workspace_transaction_shares')
                        ->where('workspace_id', $workspace->id)
                        ->whereColumn('transaction_id', 'transactions.id');
                });
        })->whereHas('wallet');
    }

    public function budgetTransactions(\App\Models\Budget $budget): Builder
    {
        $workspace = $budget->owner;
        $query = $this->transactions($workspace);
        $targets = DB::table('budgetables')->where('budget_id', $budget->id)->get();
        $categoryIds = $targets->where('budgetable_type', \App\Models\Category::class)->pluck('budgetable_id')->all();
        $groupIds = $targets->where('budgetable_type', \App\Models\Group::class)->pluck('budgetable_id')->all();
        $walletIds = $targets->where('budgetable_type', Wallet::class)->pluck('budgetable_id')->all();
        if ($categoryIds || $groupIds || $walletIds) {
            $query->where(function (Builder $targets) use ($workspace, $categoryIds, $groupIds, $walletIds) {
                $targets->whereIn('wallet_id', $walletIds);
                foreach (['category' => $categoryIds, 'group' => $groupIds] as $kind => $ids) {
                    if ($ids) {
                        $targets->orWhereExists(function ($labels) use ($workspace, $kind, $ids) {
                            $labels->selectRaw('1')->from('workspace_transaction_' . Str::plural($kind))
                                ->where('workspace_id', $workspace->id)
                                ->whereColumn('transaction_id', 'transactions.id')
                                ->whereIn("{$kind}_id", $ids);
                        });
                    }
                }
            });
        }

        return $query;
    }

    public function presentTransaction(Workspace $workspace, Transaction $transaction, User $user): array
    {
        return $this->presentTransactions($workspace, new EloquentCollection([$transaction]), $user)->first();
    }

    public function presentTransactions(Workspace $workspace, EloquentCollection $transactions, User $user): Collection
    {
        if ($transactions->isEmpty()) {
            return collect();
        }
        $transactions->loadMissing('wallet');
        $ids = $transactions->modelKeys();
        $visibleWallets = $this->wallets($workspace)->whereIn('wallets.id', $transactions->pluck('wallet_id'))
            ->pluck('wallets.id')->flip();
        $creators = User::whereIn('id', $transactions->map(fn ($item) => $item->user_id ?? $item->created_by_user_id))
            ->get()->keyBy('id');
        $admin = in_array($this->role($workspace, $user), ['owner', 'admin'], true);
        $eligible = $this->eligible($workspace);
        $shares = DB::table('workspace_transaction_shares')->where('workspace_id', $workspace->id)
            ->whereIn('transaction_id', $ids)->pluck('transaction_id')->flip();
        $refunds = DB::table('refunds')->whereIn('refund_transaction_id', $ids)->pluck('refund_transaction_id')->flip();
        $labels = [];
        foreach (['category', 'group'] as $kind) {
            $labels[$kind] = DB::table('workspace_transaction_' . Str::plural($kind))->where('workspace_id', $workspace->id)
                ->whereIn('transaction_id', $ids)->get()->groupBy('transaction_id')
                ->map(fn ($links) => $links->pluck("{$kind}_id")->all());
        }

        return $transactions->map(function ($transaction) use ($workspace, $user, $visibleWallets, $creators, $admin, $eligible, $shares, $refunds, $labels) {
            $wallet = $transaction->wallet;
            $visible = $visibleWallets->has($wallet->id);
            $creatorId = $transaction->user_id ?? $transaction->created_by_user_id;
            $creator = $creators->get($creatorId);
            $native = (int) $wallet->workspace_id === $workspace->id;
            return [
                'id' => $transaction->id, 'amount' => (float) $transaction->amount,
                'type' => \App\Events\TransactionRecorded::normalizeType($transaction->type),
                'datetime' => $transaction->datetime?->toIso8601String(), 'description' => $transaction->description,
                'currency' => $wallet->currency, 'wallet_id' => $visible ? $wallet->id : null,
                'wallet_name' => $visible ? $wallet->name : null,
                'contributor' => $creator ? trim($creator->first_name . ' ' . $creator->last_name) : 'Former member',
                'source' => $native ? 'workspace' : ($visible ? 'shared_wallet' : 'shared_transaction'),
                'can_edit' => $native && ($creatorId === $user->id || $admin) && $eligible,
                'can_unshare' => ! $native && $shares->has($transaction->id) && ($transaction->user_id === $user->id || $admin),
                'is_refund' => $refunds->has($transaction->id),
                'category_ids' => $labels['category']->get($transaction->id, []),
                'group_ids' => $labels['group']->get($transaction->id, []),
            ];
        });
    }

    public function refresh(Workspace $workspace): void
    {
        foreach ($workspace->budgets()->where('is_active', true)->pluck('id') as $id) {
            RecomputeBudgetProgressJob::dispatch($id)->afterCommit();
        }
    }

    public function revokeMember(Workspace $workspace, int $userId): void
    {
        foreach (['wallet', 'transaction'] as $resource) {
            DB::table("workspace_{$resource}_shares")->where('workspace_id', $workspace->id)->where('user_id', $userId)->delete();
        }
        $this->refresh($workspace);
    }

    public function deny(string $message, int $status = 422): never
    {
        throw new HttpResponseException(app(config('user-authentication.response_formatter'))->failure($message, $status));
    }
}
