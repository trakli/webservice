<?php

namespace App\Ai\Tools\Write;

use App\Enums\TransactionType;
use InvalidArgumentException;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Proposes marking an income transaction as money returned for an earlier
 * expense.
 *
 * This is what stops a refund counting as fresh income and keeps budget spend
 * honest, so both transactions are checked here: the refund must be income, the
 * original must be an expense, and both must belong to the acting user.
 */
class RecordRefundTool extends AbstractWriteTool
{
    public function name(): string
    {
        return 'record_refund';
    }

    public function actionType(): string
    {
        return 'refund.create';
    }

    public function description(): string
    {
        return 'Propose marking an income transaction as a refund of an earlier expense, so it is not '
            . 'counted as new income and reduces what that budget shows as spent. Give the id of the '
            . 'incoming transaction and, when known, the id of the expense it reverses (find both with '
            . 'smartql.query). The user confirms before it is saved.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::number('refund_transaction_id', 'The id of the income transaction carrying the refunded money.'),
            ParameterSpec::number('original_transaction_id', 'The id of the expense being refunded, if it is known.', required: false),
        ];
    }

    protected function buildPayload(array $arguments, ToolContext $context): array
    {
        $user = $context->user;

        $refundId = (int) ($arguments['refund_transaction_id'] ?? 0);
        $refund = $user->transactions()->find($refundId);

        if ($refund === null) {
            throw new InvalidArgumentException('That refund transaction was not found. Find the incoming transaction first, then pass its id.');
        }

        if ($refund->type !== TransactionType::INCOME->value) {
            throw new InvalidArgumentException('A refund must be the incoming transaction (an income), not the expense it reverses.');
        }

        if ($refund->refund()->exists()) {
            throw new InvalidArgumentException('That transaction is already marked as a refund.');
        }

        $originalId = null;

        if (! empty($arguments['original_transaction_id'])) {
            $originalId = (int) $arguments['original_transaction_id'];
            $original = $user->transactions()->find($originalId);

            if ($original === null) {
                throw new InvalidArgumentException('That original transaction was not found.');
            }

            if ($original->type !== TransactionType::EXPENSE->value) {
                throw new InvalidArgumentException('The transaction being refunded must be an expense.');
            }

            if ($original->id === $refund->id) {
                throw new InvalidArgumentException('A transaction cannot refund itself.');
            }
        }

        return array_filter([
            'refund_transaction_id' => $refundId,
            'original_transaction_id' => $originalId,
        ], fn ($value) => $value !== null);
    }

    protected function summarize(array $payload, ToolContext $context): string
    {
        $user = $context->user;
        $refund = $user->transactions()->find($payload['refund_transaction_id']);
        $amount = $refund?->amount ?? '';
        $what = $refund?->description ?: "transaction #{$payload['refund_transaction_id']}";

        if (empty($payload['original_transaction_id'])) {
            return trim("Mark {$amount} \"{$what}\" as a refund.");
        }

        $original = $user->transactions()->find($payload['original_transaction_id']);
        $originalWhat = $original?->description ?: "transaction #{$payload['original_transaction_id']}";

        return trim("Mark {$amount} \"{$what}\" as a refund of \"{$originalWhat}\".");
    }

    protected function reviewFields(array $payload, ToolContext $context): array
    {
        $user = $context->user;
        $refund = $user->transactions()->find($payload['refund_transaction_id'] ?? null);
        $original = $user->transactions()->find($payload['original_transaction_id'] ?? null);

        return [
            [
                'key' => 'refund_transaction_id', 'label' => 'Money received', 'type' => 'transaction',
                'value' => $payload['refund_transaction_id'] ?? null,
                'display' => (string) ($refund?->description ?? ('#' . ($payload['refund_transaction_id'] ?? ''))),
            ],
            [
                'key' => 'original_transaction_id', 'label' => 'Refund of', 'type' => 'transaction',
                'value' => $payload['original_transaction_id'] ?? null,
                'display' => (string) ($original?->description ?? 'Not linked to a specific expense'),
            ],
        ];
    }
}
