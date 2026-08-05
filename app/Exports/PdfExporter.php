<?php

namespace App\Exports;

use Barryvdh\DomPDF\Facade\Pdf;

class PdfExporter implements Exporter
{
    public function key(): string
    {
        return 'pdf';
    }

    public function mimeType(): string
    {
        return 'application/pdf';
    }

    public function extension(): string
    {
        return 'pdf';
    }

    public function export(ExportDocument $document): string
    {
        $sections = array_map(
            fn (ExportSection $section) => [
                'heading' => $section->heading,
                'note' => $section->note,
                'summary' => $section->summary,
                'columns' => $section->columns,
                // dompdf renders the whole document in one pass, so rows cannot
                // stay lazy; a generator would be consumed before layout runs.
                'rows' => $this->materialise($section->rows),
            ],
            $document->sections
        );

        return Pdf::loadView('exports.document', [
            'title' => $document->title,
            'meta' => $document->meta,
            'notices' => $document->notices,
            'sections' => $sections,
        ])->setPaper('a4', 'landscape')->output();
    }

    /**
     * @param  iterable<int, array<int, scalar|null>>  $rows
     * @return array<int, array<int, scalar|null>>
     */
    private function materialise(iterable $rows): array
    {
        return is_array($rows) ? $rows : iterator_to_array($rows, false);
    }
}
