<?php

namespace App\Jobs;

use App\Events\ImportFailed;
use App\Models\FileImport;
use App\Services\FileImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private FileImport $fileImport;

    private FileImportService $fileImportService;

    /**
     * Create a new job instance.
     */
    public function __construct(FileImport $fileImport, FileImportService $fileImportService)
    {
        $this->fileImport = $fileImport;
        $this->fileImportService = $fileImportService;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {

        $path = storage_path('app/'.$this->fileImport->file_path);

        // Check if the environment is testing
        if (app()->environment('testing')) {
            $path = storage_path('framework/testing/disks/local/'.$this->fileImport->file_path);
        }

        if (! file_exists($path)) {
            Log::error("File not found: {$path}");
            ImportFailed::dispatch($this->fileImport);

            return;
        }
        $this->fileImportService->processImports($path, $this->fileImport);
    }
}
