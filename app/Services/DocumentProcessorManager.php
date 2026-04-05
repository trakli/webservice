<?php

namespace App\Services;

use App\Contracts\DocumentProcessor;
use RuntimeException;

class DocumentProcessorManager
{
    /** @var DocumentProcessor[] */
    private array $processors = [];

    public function register(DocumentProcessor $processor): void
    {
        $this->processors[] = $processor;
    }

    public function canHandle(string $mimeType, string $extension): bool
    {
        foreach ($this->processors as $processor) {
            if ($processor->supports($mimeType, $extension)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws \RuntimeException
     */
    public function getProcessor(string $mimeType, string $extension): DocumentProcessor
    {
        foreach ($this->processors as $processor) {
            if ($processor->supports($mimeType, $extension)) {
                return $processor;
            }
        }

        throw new RuntimeException("No document processor available for type: {$mimeType} ({$extension})");
    }
}
