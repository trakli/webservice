<?php

namespace App\Services;

use App\Models\AgentProposedAction;
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
    public function __construct(protected TransactionWriter $writer)
    {
    }

    public function execute(AgentProposedAction $action): Model
    {
        return DB::transaction(fn (): Model => match ($action->action_type) {
            'transaction.create' => $this->createTransaction($action),
            'transaction.categorize' => $this->categorizeTransaction($action),
            'transaction.attach_file' => $this->attachFile($action),
            'wallet.create' => $this->createOwned($action, $action->owner->wallets()),
            'category.create' => $this->createOwned($action, $action->owner->categories()),
            'party.create' => $this->createOwned($action, $action->owner->parties()),
            default => throw new RuntimeException("Unsupported action type: {$action->action_type}"),
        });
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
