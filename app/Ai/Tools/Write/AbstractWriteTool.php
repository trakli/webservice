<?php

namespace App\Ai\Tools\Write;

use App\Ai\BlockBuilder;
use App\Ai\BlockCollector;
use App\Models\AgentProposedAction;
use App\Models\ChatSession;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Whilesmart\AgentActions\Enums\ActionRisk;
use Whilesmart\AgentActions\Enums\ActionStatus;
use Whilesmart\Agents\Enums\ToolPermission;
use Whilesmart\Agents\Tools\AbstractTool;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Base for tools that change the user's data. A write tool NEVER mutates on its
 * own: it validates, persists an AgentProposedAction, emits a proposed_action
 * block for the user to confirm, and tells the model the action is pending.
 * Execution happens only when the user confirms (ProposedActionExecutor).
 */
abstract class AbstractWriteTool extends AbstractTool
{
    public function permission(): ToolPermission
    {
        return ToolPermission::WRITE;
    }

    /**
     * The stable action identifier the executor dispatches on, e.g. "transaction.create".
     */
    abstract public function actionType(): string;

    /**
     * Build and validate the payload to persist. Throw InvalidArgumentException
     * with a user-facing message when the request cannot be fulfilled.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    abstract protected function buildPayload(array $arguments, ToolContext $context): array;

    /**
     * Every payload one call proposes. A tool acting on many records at once
     * overrides this to return one payload per record; they are then proposed as
     * a single batch the user confirms in one go, rather than a card each.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<int, array<string, mixed>>
     */
    protected function buildPayloads(array $arguments, ToolContext $context): array
    {
        return [$this->buildPayload($arguments, $context)];
    }

    /**
     * A one-line, human-facing description of what will happen on confirm.
     *
     * @param  array<string, mixed>  $payload
     */
    abstract protected function summarize(array $payload, ToolContext $context): string;

    protected function risk(): ActionRisk
    {
        return ActionRisk::High;
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $user = $context->user;

        if ($user === null) {
            return ['error' => 'No authenticated user in context.'];
        }

        $sessionId = $context->get('chat_session_id');
        if ($sessionId === null) {
            return ['error' => 'Actions can only be proposed inside a chat session.'];
        }

        try {
            $payloads = $this->buildPayloads($arguments, $context);
        } catch (InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        if ($payloads === []) {
            return ['error' => 'Nothing to propose.'];
        }

        $attributes = array_map(fn (array $payload): array => [
            'action_type' => $this->actionType(),
            'payload' => $payload,
            'summary' => $this->summarize($payload, $context),
            'risk' => $this->risk(),
            'auto_confirm' => false,
            'status' => ActionStatus::Proposed,
            'idempotency_key' => (string) Str::uuid(),
            'metadata' => [
                'chat_message_id' => $context->get('chat_message_id'),
                'tool_name' => $this->name(),
            ],
        ], $payloads);

        $shared = [
            'source_type' => (new ChatSession())->getMorphClass(),
            'source_id' => $sessionId,
        ];

        // One record stays a plain proposal: a batch of one would give the user
        // a "confirm all" over a single row for no reason.
        if (count($attributes) === 1) {
            $proposal = $user->agentActions()->create($attributes[0] + $shared);

            app(BlockCollector::class)->add(
                app(BlockBuilder::class)->proposedAction(
                    $this->blockFor($proposal, $payloads[0], $context, $sessionId)
                )
            );

            return "Proposed {$proposal->action_type}, awaiting the user's confirmation: {$proposal->summary}";
        }

        $proposals = $user->proposeActionBatch($attributes, $shared);
        $batch = $proposals->first()->batch;

        app(BlockCollector::class)->add(
            app(BlockBuilder::class)->proposedActionBatch([
                'batch' => $batch,
                'action_type' => $this->actionType(),
                'summary' => $this->summarizeBatch($payloads, $context),
                'risk' => $this->risk()->value,
                'status' => ActionStatus::Proposed->value,
                'actions' => $proposals
                    ->map(fn ($proposal, $index) => $this->blockFor($proposal, $payloads[$index], $context, $sessionId))
                    ->all(),
                'confirm_url' => "/api/v1/ai/chats/{$sessionId}/actions/batches/{$batch}/confirm",
                'reject_url' => "/api/v1/ai/chats/{$sessionId}/actions/batches/{$batch}/reject",
            ])
        );

        $count = $proposals->count();

        return "Proposed {$count} {$this->actionType()} actions as one batch, awaiting the user's confirmation. "
            . 'Tell them what you have proposed and that they can confirm or dismiss them together.';
    }

    /**
     * The client-facing shape of one proposal.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function blockFor(AgentProposedAction $proposal, array $payload, ToolContext $context, int|string $sessionId): array
    {
        return [
            'id' => $proposal->id,
            'action_type' => $proposal->action_type,
            'summary' => $proposal->summary,
            'risk' => $proposal->risk->value,
            'status' => $proposal->status->value,
            'payload' => $proposal->payload,
            'fields' => $this->reviewFields($payload, $context),
            'confirm_url' => "/api/v1/ai/chats/{$sessionId}/actions/{$proposal->id}/confirm",
            'reject_url' => "/api/v1/ai/chats/{$sessionId}/actions/{$proposal->id}/reject",
        ];
    }

    /**
     * One line describing what a whole batch will do. Override for wording that
     * reads better than a count.
     *
     * @param  array<int, array<string, mixed>>  $payloads
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function summarizeBatch(array $payloads, ToolContext $context): string
    {
        return count($payloads) . ' changes';
    }

    /**
     * Recompute the human summary and review fields for a (possibly edited)
     * payload, so a confirmed override is reflected truthfully instead of the
     * text frozen at propose time.
     *
     * @param  array<string, mixed>  $payload
     * @return array{summary: string, fields: array<int, array<string, mixed>>}
     */
    public function describe(array $payload, ToolContext $context): array
    {
        return [
            'summary' => $this->summarize($payload, $context),
            'fields' => $this->reviewFields($payload, $context),
        ];
    }

    /**
     * Human-readable {label, value} pairs the client renders as a review form so
     * the user sees exactly what will be saved. Override for nicer labels or to
     * resolve ids (wallet/category) to names. Default humanizes the payload keys.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array{label: string, value: string}>
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function reviewFields(array $payload, ToolContext $context): array
    {
        $fields = [];
        foreach ($payload as $key => $value) {
            if ($value === null || $value === [] || $key === 'client_id') {
                continue;
            }
            $fields[] = [
                'key' => $key,
                'label' => ucwords(str_replace('_', ' ', (string) $key)),
                'type' => 'text',
                'value' => $value,
                'display' => is_array($value) ? implode(', ', $value) : (string) $value,
            ];
        }

        return $fields;
    }
}
