<?php

namespace App\Services;

use App\Models\AgentProposedAction;
use App\Models\Budget;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Executes a confirmed proposed action through the same owner-scoped write path
 * a manual user action uses. The proposal's idempotency key becomes the
 * resource's client-generated id, so a retried confirm reuses the existing
 * record instead of creating a duplicate.
 */
class ProposedActionExecutor
{
    public function __construct(
        protected TransactionWriter $writer,
        protected TransferService $transfers,
        protected BudgetTargetResolver $budgetTargets,
    ) {
    }

    public function execute(AgentProposedAction $action): Model
    {
        return DB::transaction(fn (): Model => match ($action->action_type) {
            'transaction.create' => $this->createTransaction($action),
            'transaction.categorize' => $this->categorizeTransaction($action),
            'transaction.attach_file' => $this->attachFile($action),
            'transfer.create' => $this->createTransfer($action),
            'wallet.create' => $this->createOwned($action, $action->owner->wallets()),
            'category.create' => $this->createOwned($action, $action->owner->categories()),
            'party.create' => $this->createOwned($action, $action->owner->parties()),
            'group.create' => $this->createOwned($action, $action->owner->groups()),
            'reminder.create' => $this->createOwned($action, $action->owner->reminders()),
            'budget.create' => $this->createBudget($action),
            'recurring_rule.create' => $this->createRecurringRule($action),
            'refund.create' => $this->createRefund($action),
            default => throw new RuntimeException("Unsupported action type: {$action->action_type}"),
        });
    }

    /**
     * Create a budget and attach its targets. Targets are re-resolved against
     * the owner here rather than trusted from the payload, because the user may
     * have edited it between proposal and confirmation.
     */
    private function createBudget(AgentProposedAction $action): Model
    {
        $user = $action->owner;
        $payload = $action->payload;
        $targets = $payload['targets'] ?? [];
        unset($payload['targets']);

        /** @var Budget $budget */
        $budget = $user->budgets()->create($payload);

        if ($targets !== []) {
            $this->budgetTargets->apply($budget, $this->budgetTargets->resolve($user, $targets));
        }

        $budget->setClientGeneratedId($action->idempotency_key, $user);
        $budget->markAsSynced();

        return $budget;
    }

    /**
     * Attach a recurrence rule to one of the owner's transactions. The rule
     * hangs off the transaction, so the transaction is what proves ownership.
     */
    private function createRecurringRule(AgentProposedAction $action): Model
    {
        $user = $action->owner;
        $payload = $action->payload;

        /** @var Transaction $transaction */
        $transaction = $user->transactions()->findOrFail($payload['transaction_id']);
        unset($payload['transaction_id']);

        return $transaction->recurringTransactionRule()->updateOrCreate([], $payload);
    }

    /**
     * Link an income transaction to the expense it reverses. Both sides are
     * looked up through the owner, so a refund can never point at someone
     * else's transaction.
     */
    private function createRefund(AgentProposedAction $action): Model
    {
        $user = $action->owner;
        $payload = $action->payload;

        /** @var Transaction $refundTransaction */
        $refundTransaction = $user->transactions()->findOrFail($payload['refund_transaction_id']);

        $original = empty($payload['original_transaction_id'])
            ? null
            : $user->transactions()->findOrFail($payload['original_transaction_id']);

        $refund = $refundTransaction->markAsRefund($original);
        $refund->setClientGeneratedId($action->idempotency_key, $user);
        $refund->markAsSynced();

        return $refund;
    }

    private function createTransfer(AgentProposedAction $action): Model
    {
        $user = $action->owner;
        $payload = $action->payload;

        $fromWallet = $user->wallets()->findOrFail($payload['from_wallet_id']);
        $toWallet = $user->wallets()->findOrFail($payload['to_wallet_id']);
        $exchangeRate = (float) ($payload['exchange_rate'] ?? 1);
        $amountToReceive = (float) bcmul((string) $payload['amount'], (string) $exchangeRate, 4);

        $transfer = $this->transfers->transfer(
            amountToSend: (float) $payload['amount'],
            fromWallet: $fromWallet,
            amountToReceive: $amountToReceive,
            toWallet: $toWallet,
            user: $user,
            exchangeRate: $exchangeRate,
            datetime: $payload['datetime'] ?? null,
        );

        $transfer->setClientGeneratedId($action->idempotency_key, $user);
        $transfer->markAsSynced();

        return $transfer;
    }

    private function createTransaction(AgentProposedAction $action): Model
    {
        $user = $action->owner;
        $payload = $action->payload;
        $categories = $payload['categories'] ?? [];
        unset($payload['categories']);

        // A missing or blank datetime means "now" (matching the "Now" the review
        // card shows). Without this, an empty string casts to the Unix epoch.
        if (empty($payload['datetime'])) {
            $payload['datetime'] = now();
        }

        $payload['client_id'] = $action->idempotency_key;

        $this->writer->validateOwnership($user, $payload, $categories);

        return $this->writer->createCore($user, $payload, $categories);
    }

    private function categorizeTransaction(AgentProposedAction $action): Model
    {
        $user = $action->owner;
        $payload = $action->payload;

        /** @var Model $transaction */
        $transaction = $user->transactions()->findOrFail($payload['transaction_id']);

        $this->writer->validateOwnership($user, [], $payload['categories'] ?? []);

        $transaction->categories()->sync($payload['categories'] ?? []);
        $transaction->markAsSynced();

        return $transaction;
    }

    private function attachFile(AgentProposedAction $action): Model
    {
        $user = $action->owner;
        $payload = $action->payload;

        /** @var Model $transaction */
        $transaction = $user->transactions()->findOrFail($payload['transaction_id']);
        $file = \App\Models\File::findOrFail($payload['file_id']);

        $transaction->files()->create([
            'path' => $file->path,
            'type' => $file->type,
            'metadata' => $file->metadata,
        ]);

        return $transaction;
    }

    /**
     * Create an owned, Syncable resource (wallet, category, party) from the
     * proposal payload, stamping the idempotency key as its client id.
     */
    private function createOwned(AgentProposedAction $action, HasMany $relation): Model
    {
        /** @var Model $model */
        $model = $relation->create($action->payload);
        $model->setClientGeneratedId($action->idempotency_key, $action->owner);
        $model->markAsSynced();

        return $model;
    }
}
