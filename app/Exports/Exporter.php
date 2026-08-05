<?php

namespace App\Exports;

/**
 * Renders an ExportDocument into a concrete downloadable format. Implement one
 * per format and register it with the ExporterManager.
 */
interface Exporter
{
    /**
     * The format key used to select this exporter (e.g. "csv", "xlsx", "pdf").
     */
    public function key(): string;

    public function mimeType(): string;

    public function extension(): string;

    public function export(ExportDocument $document): string;
}
