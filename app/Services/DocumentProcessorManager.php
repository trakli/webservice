<?php

namespace App\Services;

use App\Contracts\DocumentProcessor;
use RuntimeException;

class DocumentProcessorManager
{
    /** @var array<int, array{processor: DocumentProcessor, priority: int, seq: int}> */
    private array $processors = [];

    private int $seq = 0;

    /**
     * Higher priority wins; equal priority falls back to registration order, so
     * a plugin can override a configured engine for a type by registering with
     * a priority above the defaults.
     */
    public function register(DocumentProcessor $processor, int $priority = 0): void
    {
        $this->processors[] = ['processor' => $processor, 'priority' => $priority, 'seq' => $this->seq++];
    }

    public function canHandle(string $mimeType, string $extension): bool
    {
        foreach ($this->ordered() as $processor) {
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
        foreach ($this->ordered() as $processor) {
            if ($processor->supports($mimeType, $extension)) {
                return $processor;
            }
        }

        throw new RuntimeException("No document processor available for type: {$mimeType} ({$extension})");
    }

    /**
     * @return DocumentProcessor[]
     */
    private function ordered(): array
    {
        $entries = $this->processors;

        usort($entries, fn ($left, $right) => $right['priority'] <=> $left['priority'] ?: $left['seq'] <=> $right['seq']);

        return array_map(fn ($entry) => $entry['processor'], $entries);
    }
}
