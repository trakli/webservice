<?php

namespace App\Services;

use App\Models\AgentProposedAction;
use App\Models\ChatMessage;
use Illuminate\Support\Collection;
use Whilesmart\AgentActions\Enums\ActionStatus;

/**
 * Keeps the proposed-action cards stored on a chat message in step with the
 * ledger, so a reloaded chat shows what actually happened to each action rather
 * than the card as first proposed. A member confirmed on its own also lives
 * inside its batch card, so both shapes are patched.
 */
class ChatActionBlocks
{
    /**
     * Reflect a single action's new status on its card, and on its place inside
     * a batch card when it belongs to one.
     *
     * @param  array{summary?: string, fields?: array}|null  $described  Regenerated
     *   summary and fields, so a confirmed edit shows rather than the frozen text.
     */
    public function markStatus(AgentProposedAction $action, ActionStatus $status, ?array $described = null): void
    {
        $message = ChatMessage::find($action->metadata['chat_message_id'] ?? null);
        if ($message === null) {
            return;
        }

        $patch = fn (array $block): array => $this->applyStatus($block, $action, $status, $described);
        $changed = $this->rewriteBlocks($message, fn (array $block): array => $this->markInBlock($block, (int) $action->id, $patch));

        // The batch card's own status is derived from its members, so it has to
        // be recomputed once one of them changes underneath it.
        if ($changed && $action->batch) {
            $this->syncBatch((string) $action->batch);
        }
    }

    /**
     * Rewrite a batch card from the ledger: each member's status and the card's
     * own rolled-up status.
     */
    public function syncBatch(string $batch): void
    {
        $actions = AgentProposedAction::query()->forBatch($batch)->get()->keyBy('id');
        $message = ChatMessage::find($actions->first()?->metadata['chat_message_id'] ?? null);
        if ($message === null) {
            return;
        }

        $this->rewriteBlocks($message, fn (array $block): array => $this->syncBatchInBlock($block, $batch, $actions));
    }

    /**
     * Run a mutator over every block on a message, persisting once if any block
     * changed. Comparing by value keeps each mutator simple and side-effect-free.
     */
    private function rewriteBlocks(ChatMessage $message, callable $mutator): bool
    {
        $result = $message->result ?? [];
        $blocks = $result['blocks'] ?? [];

        if (! is_array($blocks)) {
            return false;
        }

        $changed = false;
        foreach ($blocks as $i => $block) {
            if (! is_array($block)) {
                continue;
            }
            $next = $mutator($block);
            if ($next !== $block) {
                $blocks[$i] = $next;
                $changed = true;
            }
        }

        if ($changed) {
            $result['blocks'] = $blocks;
            $message->update(['result' => $result]);
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function markInBlock(array $block, int $actionId, callable $patch): array
    {
        $type = $block['type'] ?? null;

        if ($type === 'proposed_action' && (int) ($block['id'] ?? 0) === $actionId) {
            return $patch($block);
        }

        if ($type === 'proposed_action_batch' && is_array($block['actions'] ?? null)) {
            $block['actions'] = array_map(
                fn ($member) => is_array($member) && (int) ($member['id'] ?? 0) === $actionId ? $patch($member) : $member,
                $block['actions']
            );
        }

        return $block;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  Collection<int, AgentProposedAction>  $actions
     * @return array<string, mixed>
     */
    private function syncBatchInBlock(array $block, string $batch, Collection $actions): array
    {
        if (($block['type'] ?? null) !== 'proposed_action_batch' || ($block['batch'] ?? null) !== $batch) {
            return $block;
        }

        if (is_array($block['actions'] ?? null)) {
            $block['actions'] = array_map(function ($member) use ($actions) {
                $current = is_array($member) ? $actions->get((int) ($member['id'] ?? 0)) : null;
                if ($current !== null) {
                    $member['status'] = $current->status->value;
                }

                return $member;
            }, $block['actions']);
        }

        $block['status'] = $this->batchStatus($actions);

        return $block;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function applyStatus(array $block, AgentProposedAction $action, ActionStatus $status, ?array $described): array
    {
        $block['status'] = $status->value;

        if ($described !== null) {
            $block['summary'] = $described['summary'];
            $block['fields'] = $described['fields'];
            $block['payload'] = $action->payload;
        }

        return $block;
    }

    /**
     * A batch is settled only once no member is still awaiting a decision.
     *
     * @param  Collection<int, AgentProposedAction>  $actions
     */
    private function batchStatus(Collection $actions): string
    {
        $statuses = $actions->map(fn (AgentProposedAction $action): ActionStatus => $action->status);

        if ($statuses->contains(ActionStatus::Proposed) || $statuses->contains(ActionStatus::Confirmed)) {
            return ActionStatus::Proposed->value;
        }

        if ($statuses->contains(ActionStatus::Executed)) {
            return ActionStatus::Executed->value;
        }

        if ($statuses->every(fn (ActionStatus $status): bool => $status === ActionStatus::Rejected)) {
            return ActionStatus::Rejected->value;
        }

        return ActionStatus::Failed->value;
    }
}
