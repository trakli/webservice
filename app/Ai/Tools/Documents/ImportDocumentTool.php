<?php

namespace App\Ai\Tools\Documents;

use App\Ai\BlockBuilder;
use App\Ai\BlockCollector;
use App\Jobs\AnalyzeImportJob;
use App\Models\ChatMessage;
use App\Services\DocumentProcessorManager;
use Illuminate\Support\Facades\Storage;
use Whilesmart\Agents\Enums\ToolPermission;
use Whilesmart\Agents\Tools\AbstractTool;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Starts analysis of a document the user attached to the chat (a bank statement,
 * receipt or invoice) by handing it to the existing import pipeline, and renders
 * an in-chat import review widget. The actual transactions are created only when
 * the user confirms the suggestions through the import flow.
 */
class ImportDocumentTool extends AbstractTool
{
    public function __construct(private DocumentProcessorManager $processors)
    {
    }

    public function name(): string
    {
        return 'import_document';
    }

    public function description(): string
    {
        return 'Analyze a document the user has attached to this chat (bank statement, receipt, '
            . 'invoice) and show an import review. Call this when the user uploads a file and asks '
            . 'to import or extract transactions from it.';
    }

    public function permission(): ToolPermission
    {
        // Non-destructive: starts analysis and shows suggestions. The user still
        // confirms the actual import, so this is not gated as a write.
        return ToolPermission::READ;
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $user = $context->user;
        $sessionId = $context->get('chat_session_id');

        if ($user === null || $sessionId === null) {
            return ['error' => 'No chat context available.'];
        }

        $file = $this->latestAttachment($sessionId);
        if ($file === null) {
            return ['error' => 'No document is attached to this chat. Ask the user to attach a file first.'];
        }

        $path = $file->path;
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeType = Storage::exists($path) ? (Storage::mimeType($path) ?: '') : '';

        if (! $this->processors->canHandle($mimeType, $extension)) {
            return ['error' => 'That file type cannot be imported.'];
        }

        $processor = $this->processors->getProcessor($mimeType, $extension);

        $session = $user->importSessions()->create([
            'file_name' => basename($path),
            'file_type' => $extension,
            'document_type' => $file->metadata['document_type'] ?? ($arguments['document_type'] ?? null),
            'processor' => class_basename($processor),
            'status' => 'analyzing',
            'suggestions' => [],
        ]);

        AnalyzeImportJob::dispatch($session->id, $path, basename($path), $mimeType, $extension);

        app(BlockCollector::class)->add(
            app(BlockBuilder::class)->importReview($session->id, 'analyzing', basename($path))
        );

        return "Started analyzing the attached document (import session #{$session->id}). The user will review and confirm the suggested transactions.";
    }

    private function latestAttachment(int $sessionId): ?object
    {
        $message = ChatMessage::query()
            ->where('chat_session_id', $sessionId)
            ->whereHas('files')
            ->latest('id')
            ->first();

        return $message?->files()->latest('id')->first();
    }
}
