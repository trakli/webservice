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

    /**
     * Largest table this format can render within one request. Measured against
     * a 128MB limit: dompdf lays the whole document out in memory and costs
     * about 0.4MB a row, PhpSpreadsheet about 12KB, and CSV streams.
     */
    public function maxRows(): int;

    public function export(ExportDocument $document): string;
}
