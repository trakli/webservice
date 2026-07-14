<?php

namespace App\Ai\Tools\Documents;

use App\Ai\Tools\ResolvesChatAttachment;
use App\Ai\Tools\Write\RecordTransactionTool;
use App\Models\User;
use App\Services\DocumentProcessorManager;
use App\Services\DocumentProcessors\RemoteDocumentProcessor;
use App\Types\TransactionSuggestion;
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

        $processor = $this->processors->getProcessor($mimeType, $extension);
        if (! $processor instanceof RemoteDocumentProcessor) {
            throw new InvalidArgumentException('That file type cannot be read as a receipt.');
        }

        $uploaded = new UploadedFile(Storage::path($path), basename($path), $mimeType, null, true);
        $suggestion = $processor->extractReceipt($uploaded);
        if ($suggestion === null || ! $suggestion->amount) {
            throw new InvalidArgumentException('Could not read a transaction from that receipt.');
        }

        return parent::buildPayload($this->receiptArguments($user, $suggestion), $context);
    }

    /**
     * Turn the extracted receipt into RecordTransactionTool arguments. The store
     * becomes the party only when it already exists (the resolver rejects an
     * unknown one); otherwise it stays in the description.
     *
     * @return array<string, mixed>
     */
    private function receiptArguments(User $user, TransactionSuggestion $suggestion): array
    {
        $description = trim((string) $suggestion->description);

        // Default to the user's first wallet so confirm works; the user can
        // change it in the review form.
        $args = [
            'amount' => $suggestion->amount,
            'type' => 'expense',
            'wallet_id' => $user->wallets()->value('id'),
            'datetime' => $suggestion->date,
        ];

        $merchant = trim((string) $suggestion->party);
        if ($merchant !== '') {
            $party = $user->parties()->whereRaw('LOWER(name) = ?', [mb_strtolower($merchant)])->first();
            if ($party !== null) {
                $args['party_id'] = $party->id;
            } elseif ($description === '') {
                $description = $merchant;
            } else {
                $description = "{$merchant}: {$description}";
            }
        }

        $args['description'] = $description === '' ? 'Receipt' : $description;

        $category = trim((string) $suggestion->category);
        if ($category !== '' && $user->categories()->whereRaw('LOWER(name) = ?', [mb_strtolower($category)])->exists()) {
            $args['category_names'] = [$category];
        }

        return $args;
    }
}
