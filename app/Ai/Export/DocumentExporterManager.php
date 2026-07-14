<?php

namespace App\Ai\Export;

use InvalidArgumentException;

/**
 * Resolves a DocumentExporter by its format key. Register exporters here (or via
 * the container); adding a format is a one-line addition.
 */
class DocumentExporterManager
{
    /** @var array<string, DocumentExporter> */
    private array $exporters = [];

    public function __construct()
    {
        $this->register(new MarkdownDocumentExporter());
    }

    public function register(DocumentExporter $exporter): void
    {
        $this->exporters[$exporter->key()] = $exporter;
    }

    public function has(string $format): bool
    {
        return isset($this->exporters[$format]);
    }

    public function for(string $format): DocumentExporter
    {
        if (! isset($this->exporters[$format])) {
            throw new InvalidArgumentException("No exporter for format: {$format}");
        }

        return $this->exporters[$format];
    }

    /**
     * @return array<int, string>
     */
    public function formats(): array
    {
        return array_keys($this->exporters);
    }
}
