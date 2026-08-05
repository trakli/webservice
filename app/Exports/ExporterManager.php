<?php

namespace App\Exports;

use InvalidArgumentException;

class ExporterManager
{
    /** @var array<string, Exporter> */
    private array $exporters = [];

    public function __construct()
    {
        $this->register(new CsvExporter());
        $this->register(new XlsxExporter());
        $this->register(new PdfExporter());
    }

    public function register(Exporter $exporter): void
    {
        $this->exporters[$exporter->key()] = $exporter;
    }

    public function has(string $format): bool
    {
        return isset($this->exporters[$format]);
    }

    public function for(string $format): Exporter
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

    /**
     * @return array<string, int>
     */
    public function rowLimits(): array
    {
        return array_map(fn (Exporter $exporter) => $exporter->maxRows(), $this->exporters);
    }
}
