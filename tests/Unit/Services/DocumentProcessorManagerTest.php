<?php

namespace Tests\Unit\Services;

use App\Contracts\DocumentProcessor;
use App\Services\DocumentProcessorManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DocumentProcessorManagerTest extends TestCase
{
    private DocumentProcessorManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new DocumentProcessorManager();
    }

    public function test_can_handle_returns_true_for_supported_types(): void
    {
        $processor = $this->createMockProcessor('text/csv', 'csv');
        $this->manager->register($processor);

        $this->assertTrue($this->manager->canHandle('text/csv', 'csv'));
    }

    public function test_can_handle_returns_false_for_unsupported_types(): void
    {
        $processor = $this->createMockProcessor('text/csv', 'csv');
        $this->manager->register($processor);

        $this->assertFalse($this->manager->canHandle('application/xml', 'xml'));
    }

    public function test_can_handle_returns_false_when_no_processors_registered(): void
    {
        $this->assertFalse($this->manager->canHandle('text/csv', 'csv'));
    }

    public function test_get_processor_returns_correct_processor(): void
    {
        $csvProcessor = $this->createMockProcessor('text/csv', 'csv');
        $pdfProcessor = $this->createMockProcessor('application/pdf', 'pdf');

        $this->manager->register($csvProcessor);
        $this->manager->register($pdfProcessor);

        $this->assertSame($csvProcessor, $this->manager->getProcessor('text/csv', 'csv'));
        $this->assertSame($pdfProcessor, $this->manager->getProcessor('application/pdf', 'pdf'));
    }

    public function test_get_processor_throws_runtime_exception_for_unsupported_types(): void
    {
        $processor = $this->createMockProcessor('text/csv', 'csv');
        $this->manager->register($processor);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No document processor available for type: application/xml (xml)');

        $this->manager->getProcessor('application/xml', 'xml');
    }

    public function test_get_processor_throws_when_no_processors_registered(): void
    {
        $this->expectException(RuntimeException::class);

        $this->manager->getProcessor('text/csv', 'csv');
    }

    public function test_multiple_processors_first_match_wins(): void
    {
        $firstProcessor = $this->createMockProcessor('text/csv', 'csv');
        $secondProcessor = $this->createMockProcessor('text/csv', 'csv');

        $this->manager->register($firstProcessor);
        $this->manager->register($secondProcessor);

        $this->assertSame($firstProcessor, $this->manager->getProcessor('text/csv', 'csv'));
    }

    public function test_higher_priority_wins_over_earlier_registration(): void
    {
        $default = $this->createMockProcessor('application/pdf', 'pdf');
        $preferred = $this->createMockProcessor('application/pdf', 'pdf');

        $this->manager->register($default);
        $this->manager->register($preferred, 100);

        $this->assertSame($preferred, $this->manager->getProcessor('application/pdf', 'pdf'));
    }

    public function test_equal_priority_preserves_registration_order(): void
    {
        $first = $this->createMockProcessor('text/csv', 'csv');
        $second = $this->createMockProcessor('text/csv', 'csv');

        $this->manager->register($first, 5);
        $this->manager->register($second, 5);

        $this->assertSame($first, $this->manager->getProcessor('text/csv', 'csv'));
    }

    private function createMockProcessor(string $supportedMime, string $supportedExt): DocumentProcessor
    {
        $processor = $this->createMock(DocumentProcessor::class);

        $processor->method('supports')
            ->willReturnCallback(function (string $mimeType, string $extension) use ($supportedMime, $supportedExt) {
                return $mimeType === $supportedMime || $extension === $supportedExt;
            });

        return $processor;
    }
}
