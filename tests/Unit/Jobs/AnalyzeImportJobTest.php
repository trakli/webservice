<?php

namespace Tests\Unit\Jobs;

use App\Contracts\DocumentProcessor;
use App\Jobs\AnalyzeImportJob;
use App\Models\User;
use App\Services\DocumentProcessorManager;
use App\Services\DuplicateDetectionService;
use App\Services\SuggestionEnricher;
use App\Types\TransactionSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnalyzeImportJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_job_processes_file_and_updates_session_to_ready(): void
    {
        Storage::fake('local');

        $csv = "amount,currency,type,party,wallet,category,description,date\n"
            . "100,USD,expense,Store,Wallet,Food,Lunch,2025-01-01\n";

        $storedPath = 'import-analyze/test.csv';
        Storage::put($storedPath, $csv);

        $session = $this->user->importSessions()->create([
            'file_name' => 'test.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'analyzing',
            'suggestions' => [],
        ]);

        $suggestions = [
            new TransactionSuggestion(
                amount: 100.00,
                currency: 'USD',
                type: 'expense',
                party: 'Store',
                wallet: 'Wallet',
                category: 'Food',
                description: 'Lunch',
                date: '2025-01-01',
                confidence: 1.0,
                documentType: 'csv',
            ),
        ];

        $mockProcessor = $this->createMock(DocumentProcessor::class);
        $mockProcessor->method('process')->willReturn($suggestions);

        $processorManager = $this->createMock(DocumentProcessorManager::class);
        $processorManager->method('getProcessor')->willReturn($mockProcessor);

        $enricher = $this->createMock(SuggestionEnricher::class);
        $enricher->method('enrich')->willReturn($suggestions);

        $duplicateService = $this->createMock(DuplicateDetectionService::class);
        $duplicateService->method('checkBatch')->willReturn([null]);

        $job = new AnalyzeImportJob(
            $session->id,
            $storedPath,
            'test.csv',
            'text/csv',
            'csv',
        );

        $job->handle($processorManager, $enricher, $duplicateService);

        $session->refresh();
        $this->assertEquals('ready', $session->status);
        $this->assertCount(1, $session->suggestions);
        $this->assertEquals(100.00, $session->suggestions[0]['amount']);
        $this->assertEquals(1, $session->metadata['total_suggestions']);
    }

    public function test_job_updates_session_to_failed_when_file_not_found(): void
    {
        Storage::fake('local');

        $session = $this->user->importSessions()->create([
            'file_name' => 'missing.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'analyzing',
            'suggestions' => [],
        ]);

        $processorManager = $this->createMock(DocumentProcessorManager::class);
        $enricher = $this->createMock(SuggestionEnricher::class);
        $duplicateService = $this->createMock(DuplicateDetectionService::class);

        $job = new AnalyzeImportJob(
            $session->id,
            'import-analyze/nonexistent.csv',
            'missing.csv',
            'text/csv',
            'csv',
        );

        $job->handle($processorManager, $enricher, $duplicateService);

        $session->refresh();
        $this->assertEquals('failed', $session->status);
        $this->assertArrayHasKey('error', $session->metadata);
        $this->assertStringContainsString('not found', $session->metadata['error']);
    }

    public function test_job_updates_session_to_failed_when_no_transactions_extracted(): void
    {
        Storage::fake('local');

        $storedPath = 'import-analyze/empty.csv';
        Storage::put($storedPath, "amount,currency,type\n");

        $session = $this->user->importSessions()->create([
            'file_name' => 'empty.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'analyzing',
            'suggestions' => [],
        ]);

        $mockProcessor = $this->createMock(DocumentProcessor::class);
        $mockProcessor->method('process')->willReturn([]);

        $processorManager = $this->createMock(DocumentProcessorManager::class);
        $processorManager->method('getProcessor')->willReturn($mockProcessor);

        $enricher = $this->createMock(SuggestionEnricher::class);
        $duplicateService = $this->createMock(DuplicateDetectionService::class);

        $job = new AnalyzeImportJob(
            $session->id,
            $storedPath,
            'empty.csv',
            'text/csv',
            'csv',
        );

        $job->handle($processorManager, $enricher, $duplicateService);

        $session->refresh();
        $this->assertEquals('failed', $session->status);
        $this->assertStringContainsString('No transactions', $session->metadata['error']);
    }

    public function test_job_goes_through_extracting_enriching_checking_stages(): void
    {
        Storage::fake('local');

        $storedPath = 'import-analyze/stages.csv';
        Storage::put($storedPath, "amount,currency,type,party,wallet,category,description,date\n50,USD,expense,X,W,C,D,2025-01-01\n");

        $session = $this->user->importSessions()->create([
            'file_name' => 'stages.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'analyzing',
            'suggestions' => [],
        ]);

        $suggestions = [
            new TransactionSuggestion(
                amount: 50.00,
                currency: 'USD',
                type: 'expense',
                date: '2025-01-01',
            ),
        ];

        $statusLog = [];

        $mockProcessor = $this->createMock(DocumentProcessor::class);
        $mockProcessor->method('process')->willReturn($suggestions);

        $processorManager = $this->createMock(DocumentProcessorManager::class);
        $processorManager->method('getProcessor')->willReturn($mockProcessor);

        $enricher = $this->createMock(SuggestionEnricher::class);
        $enricher->method('enrich')->willReturn($suggestions);

        $duplicateService = $this->createMock(DuplicateDetectionService::class);
        $duplicateService->method('checkBatch')->willReturn([null]);

        $job = new AnalyzeImportJob(
            $session->id,
            $storedPath,
            'stages.csv',
            'text/csv',
            'csv',
        );

        $job->handle($processorManager, $enricher, $duplicateService);

        // Final state should be 'ready' after going through all stages
        $session->refresh();
        $this->assertEquals('ready', $session->status);
    }

    public function test_job_handles_exception_and_sets_failed_status(): void
    {
        Storage::fake('local');

        $storedPath = 'import-analyze/error.csv';
        Storage::put($storedPath, "data\n");

        $session = $this->user->importSessions()->create([
            'file_name' => 'error.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'analyzing',
            'suggestions' => [],
        ]);

        $processorManager = $this->createMock(DocumentProcessorManager::class);
        $processorManager->method('getProcessor')
            ->willThrowException(new \RuntimeException('Processor error'));

        $enricher = $this->createMock(SuggestionEnricher::class);
        $duplicateService = $this->createMock(DuplicateDetectionService::class);

        $job = new AnalyzeImportJob(
            $session->id,
            $storedPath,
            'error.csv',
            'text/csv',
            'csv',
        );

        $job->handle($processorManager, $enricher, $duplicateService);

        $session->refresh();
        $this->assertEquals('failed', $session->status);
        $this->assertStringContainsString('Processor error', $session->metadata['error']);
    }

    public function test_job_cleans_up_stored_file(): void
    {
        Storage::fake('local');

        $storedPath = 'import-analyze/cleanup.csv';
        Storage::put($storedPath, "amount,currency,type,party,wallet,category,description,date\n50,USD,expense,X,W,C,D,2025-01-01\n");

        $session = $this->user->importSessions()->create([
            'file_name' => 'cleanup.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'analyzing',
            'suggestions' => [],
        ]);

        $suggestions = [
            new TransactionSuggestion(
                amount: 50.00,
                currency: 'USD',
                type: 'expense',
                date: '2025-01-01',
            ),
        ];

        $mockProcessor = $this->createMock(DocumentProcessor::class);
        $mockProcessor->method('process')->willReturn($suggestions);

        $processorManager = $this->createMock(DocumentProcessorManager::class);
        $processorManager->method('getProcessor')->willReturn($mockProcessor);

        $enricher = $this->createMock(SuggestionEnricher::class);
        $enricher->method('enrich')->willReturn($suggestions);

        $duplicateService = $this->createMock(DuplicateDetectionService::class);
        $duplicateService->method('checkBatch')->willReturn([null]);

        $job = new AnalyzeImportJob(
            $session->id,
            $storedPath,
            'cleanup.csv',
            'text/csv',
            'csv',
        );

        $job->handle($processorManager, $enricher, $duplicateService);

        Storage::assertMissing($storedPath);
    }

    public function test_job_handles_nonexistent_session(): void
    {
        Storage::fake('local');

        $storedPath = 'import-analyze/orphan.csv';
        Storage::put($storedPath, "data\n");

        $processorManager = $this->createMock(DocumentProcessorManager::class);
        $enricher = $this->createMock(SuggestionEnricher::class);
        $duplicateService = $this->createMock(DuplicateDetectionService::class);

        $job = new AnalyzeImportJob(
            99999,
            $storedPath,
            'orphan.csv',
            'text/csv',
            'csv',
        );

        // Should not throw, just log and clean up
        $job->handle($processorManager, $enricher, $duplicateService);

        Storage::assertMissing($storedPath);
    }
}
