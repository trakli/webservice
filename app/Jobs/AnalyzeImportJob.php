<?php

namespace App\Jobs;

use App\Models\ImportSession;
use App\Services\DocumentProcessorManager;
use App\Services\DuplicateDetectionService;
use App\Services\SuggestionEnricher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AnalyzeImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        private int $sessionId,
        private string $storedFilePath,
        private string $originalName,
        private string $mimeType,
        private string $extension,
    ) {
    }

    public function handle(
        DocumentProcessorManager $processorManager,
        SuggestionEnricher $enricher,
        DuplicateDetectionService $duplicateService,
    ): void {
        $session = ImportSession::find($this->sessionId);

        if (! $session) {
            Log::error('AnalyzeImportJob: session not found', ['id' => $this->sessionId]);
            $this->cleanup();

            return;
        }

        $user = $session->user;

        try {
            $filePath = Storage::path($this->storedFilePath);

            if (! file_exists($filePath)) {
                Log::error('AnalyzeImportJob: file not found', ['path' => $filePath]);
                $session->update([
                    'status' => 'failed',
                    'metadata' => ['error' => 'Uploaded file not found'],
                ]);

                return;
            }

            $file = new UploadedFile($filePath, $this->originalName, $this->mimeType, null, true);

            // Stage 1: Extracting
            $session->update(['status' => 'extracting']);

            $processor = $processorManager->getProcessor($this->mimeType, $this->extension);
            $suggestions = $processor->process($file, $user);

            if (empty($suggestions)) {
                $session->update([
                    'status' => 'failed',
                    'metadata' => ['error' => 'No transactions could be extracted from this document.'],
                ]);
                $this->cleanup();

                return;
            }

            // Stage 2: Enriching
            $session->update(['status' => 'enriching']);

            $suggestions = $enricher->enrich($suggestions, $user);

            // Stage 3: Checking duplicates
            $session->update(['status' => 'checking']);

            $duplicates = $duplicateService->checkBatch($suggestions, $user);

            // Build suggestions array with duplicate info
            $suggestionsData = [];
            foreach ($suggestions as $i => $suggestion) {
                $entry = $suggestion->toArray();
                $entry['duplicate'] = $duplicates[$i]?->toArray();
                $suggestionsData[] = $entry;
            }

            $session->update([
                'status' => 'ready',
                'suggestions' => $suggestionsData,
                'metadata' => [
                    'total_suggestions' => count($suggestions),
                    'duplicates_found' => count(array_filter($duplicates)),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('AnalyzeImportJob failed', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);

            $session->update([
                'status' => 'failed',
                'metadata' => ['error' => 'Analysis failed: ' . $e->getMessage()],
            ]);
        } finally {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        Storage::delete($this->storedFilePath);
    }
}
