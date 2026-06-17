<?php

namespace App\Ai\Tools\Documents;

use App\Ai\Tools\ResolvesChatAttachment;
use App\Ai\Tools\Write\RecordTransactionTool;
use App\Services\DocumentProcessorManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Reads a receipt/photo the user attached to the chat and proposes a transaction
 * from it. Reuses RecordTransactionTool's proposal + editable review form, so the
 * user can correct the extracted values (and pick a wallet) before confirming.
 * Extraction itself runs through the existing document processor.
 */
class ExtractReceiptTool extends RecordTransactionTool
{
    use ResolvesChatAttachment;

    public function __construct(private DocumentProcessorManager $processors)
    {
    }

    public function name(): string
    {
        return 'extract_receipt';
    }

    public function description(): string
    {
        return 'Read a receipt or photo the user attached to this chat and propose a transaction '
            . 'from it (amount, description, date). The user reviews and picks the wallet before saving.';
    }

    public function parameters(): array
    {
        return [];
    }

    protected function buildPayload(array $arguments, ToolContext $context): array
    {
        $user = $context->user;

        $file = $this->latestAttachment((int) $context->get('chat_session_id'));
        if ($file === null) {
            throw new InvalidArgumentException('Ask the user to attach a receipt image first.');
        }

        $path = $file->path;
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeType = Storage::exists($path) ? (Storage::mimeType($path) ?: '') : '';

        if (! $this->processors->canHandle($mimeType, $extension)) {
            throw new InvalidArgumentException('That file type cannot be read as a receipt.');
        }

        $uploaded = new UploadedFile(Storage::path($path), basename($path), $mimeType, null, true);
        $suggestions = $this->processors->getProcessor($mimeType, $extension)->process($uploaded, $user);

        $suggestion = $suggestions[0] ?? null;
        if (! is_array($suggestion) || empty($suggestion['amount'])) {
            throw new InvalidArgumentException('Could not read a transaction from that receipt.');
        }

        // Default to the user's first wallet so confirm works; the user can change
        // it in the review form.
        $walletId = $user->wallets()->value('id');

        return array_filter([
            'amount' => (float) $suggestion['amount'],
            'type' => in_array($suggestion['type'] ?? null, ['income', 'expense'], true) ? $suggestion['type'] : 'expense',
            'wallet_id' => $walletId,
            'description' => $suggestion['description'] ?? 'Receipt',
            'datetime' => $suggestion['date'] ?? null,
        ], fn ($value) => $value !== null);
    }
}
