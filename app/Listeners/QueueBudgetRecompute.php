<?php

namespace App\Listeners;

use App\Contracts\OwnerResolver;
use App\Events\TransactionRecorded;
use App\Events\TransactionSnapshot;
use App\Jobs\RecomputeBudgetProgressJob;
use App\Models\Budget;

class QueueBudgetRecompute
{
    public function __construct(
        protected OwnerResolver $ownerResolver,
    ) {
    }

    public function handle(TransactionRecorded $event): void
    {
        $affectedIds = [];
        foreach ([$event->before, $event->after] as $snapshot) {
            if (! $snapshot) {
                continue;
            }
            $workspaceIds = \Illuminate\Support\Facades\DB::table('workspace_wallet_shares')->where('wallet_id', $snapshot->walletId)->pluck('workspace_id')
                ->merge(\Illuminate\Support\Facades\DB::table('workspace_transaction_shares')->where('transaction_id', $snapshot
                    ->transactionId)->pluck('workspace_id'))
                ->merge(\App\Models\Wallet::withTrashed()->whereKey($snapshot->walletId)->whereNotNull('workspace_id')->pluck('workspace_id'));
            foreach (
                Budget::where('owner_type', \App\Models\Workspace::class)->whereIn('owner_id', $workspaceIds)->where('is_active', true)
                ->pluck('id') as $id
            ) {
                $affectedIds[$id] = true;
            }
        }

        foreach ([$event->before, $event->after] as $snapshot) {
            if ($snapshot === null) {
                continue;
            }

            foreach ($this->budgetIdsForSnapshot($snapshot) as $id) {
                $affectedIds[$id] = true;
            }
        }

        foreach (array_keys($affectedIds) as $budgetId) {
            RecomputeBudgetProgressJob::dispatch($budgetId);
        }
    }

    /**
     * Candidate budgets are those (a) owned by someone whose resolved
     * user_ids include the transaction's user, (b) targeting at least one
     * of the transaction's categories/groups/wallet, and (c) whose period
     * window would include the transaction's datetime.
     *
     * @return array<int>
     */
    protected function budgetIdsForSnapshot(TransactionSnapshot $snapshot): array
    {
        $candidates = Budget::query()
            ->where('is_active', true)
            ->where(function ($q) use ($snapshot) {
                $q->whereHas('categories', function ($cq) use ($snapshot) {
                    $cq->whereIn('categories.id', $snapshot->categoryIds ?: [0]);
                })
                    ->orWhereHas('groups', function ($gq) use ($snapshot) {
                        $gq->whereIn('groups.id', $snapshot->groupIds ?: [0]);
                    })
                    ->orWhereHas('wallets', function ($wq) use ($snapshot) {
                        $wq->where('wallets.id', $snapshot->walletId ?? 0);
                    });
            })
            ->get(['id', 'owner_type', 'owner_id']);

        $ids = [];

        foreach ($candidates as $budget) {
            $userIds = $this->ownerResolver->resolveUserIds($budget->owner);
            if (in_array($snapshot->userId, $userIds, true)) {
                $ids[] = $budget->id;
            }
        }

        return $ids;
    }
}
