<?php

namespace App\Ai\Tools\Write;

use App\Ai\Tools\ResolvesChatAttachment;
use App\Models\AgentProposedAction;
use InvalidArgumentException;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Proposes attaching a file the user uploaded in the chat (e.g. a receipt) to an
 * existing transaction. Low risk: it only links a document.
 */
class AttachToTransactionTool extends AbstractWriteTool
{
    use ResolvesChatAttachment;

    public function name(): string
    {
        return 'attach_to_transaction';
    }

    public function actionType(): string
    {
        return 'transaction.attach_file';
    }

    public function description(): string
    {
        return 'Attach the file the user uploaded in this chat (e.g. a receipt) to one of their '
            . 'existing transactions. Provide the transaction id.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::number('transaction_id', 'The id of the transaction to attach the file to.'),
        ];
    }

    protected function risk(): string
    {
        return AgentProposedAction::RISK_LOW;
    }

    protected function buildPayload(array $arguments, ToolContext $context): array
    {
        $user = $context->user;

        $transactionId = (int) ($arguments['transaction_id'] ?? 0);
        if ($transactionId <= 0 || ! $user->transactions()->whereKey($transactionId)->exists()) {
            throw new InvalidArgumentException('That transaction was not found among your records.');
        }

        $file = $this->latestAttachment((int) $context->get('chat_session_id'));
        if ($file === null) {
            throw new InvalidArgumentException('No file is attached to this chat to attach.');
        }

        return [
            'transaction_id' => $transactionId,
            'file_id' => (int) $file->id,
        ];
    }

    protected function summarize(array $payload, ToolContext $context): string
    {
        return "Attach the uploaded file to transaction #{$payload['transaction_id']}.";
    }

    protected function reviewFields(array $payload, ToolContext $context): array
    {
        return [
            [
                'key' => 'transaction_id',
                'label' => 'Transaction',
                'type' => 'number',
                'value' => $payload['transaction_id'],
                'display' => '#' . $payload['transaction_id'],
            ],
        ];
    }
}
