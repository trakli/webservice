<?php

namespace App\Ai\Export;

/**
 * Exports a canvas document (an ordered list of widget blocks) into a concrete
 * downloadable format. Implement one per format and register it with the
 * DocumentExporterManager — adding HTML, CSV or a server-side PDF later is just
 * a new class, no controller changes.
 */
interface DocumentExporter
{
    /**
     * The format key used to select this exporter (e.g. "md", "html").
     */
    public function key(): string;

    public function mimeType(): string;

    public function extension(): string;

    /**
     * Render the document to a string in this format.
     *
     * @param  array<int, array<string, mixed>>  $blocks  The canvas blocks.
     */
    public function export(array $blocks, string $title): string;
}
