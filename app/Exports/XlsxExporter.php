<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class XlsxExporter implements Exporter
{
    public function key(): string
    {
        return 'xlsx';
    }

    public function mimeType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function extension(): string
    {
        return 'xlsx';
    }

    public function maxRows(): int
    {
        return 5000;
    }

    public function export(ExportDocument $document): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $sections = $document->sections;
        if (empty($sections)) {
            $sections = [new ExportSection(__('Summary'))];
        }

        foreach (array_values($sections) as $index => $section) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->sheetTitle($section->heading, $index));

            $rows = $index === 0
                ? $this->headerRows($document)
                : [];

            $rows[] = [$section->heading];

            if ($section->note !== null) {
                $rows[] = [$section->note];
            }

            foreach ($section->summary as $label => $value) {
                $rows[] = [$label, $value];
            }

            if ($section->hasTable()) {
                $rows[] = [];
                $rows[] = $section->columns;

                foreach ($section->rows as $row) {
                    $rows[] = array_values($row);
                }
            }

            $sheet->fromArray($rows, null, 'A1', true);
            $this->autoSize($sheet, $rows);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $this->render($spreadsheet);
    }

    /**
     * @return array<int, array<int, scalar|null>>
     */
    private function headerRows(ExportDocument $document): array
    {
        $rows = [[$document->title]];

        foreach ($document->meta as $label => $value) {
            $rows[] = [$label, $value];
        }

        foreach ($document->notices as $notice) {
            $rows[] = [$notice];
        }

        $rows[] = [];

        return $rows;
    }

    /**
     * Excel rejects sheet names over 31 characters or containing []:*?/\, and
     * silently breaks on duplicates, so every title is normalised and suffixed.
     */
    private function sheetTitle(string $heading, int $index): string
    {
        $title = preg_replace('/[\[\]:*?\/\\\\]/', ' ', $heading) ?? 'Sheet';
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? '');

        if ($title === '') {
            $title = 'Sheet';
        }

        $suffix = ' ' . ($index + 1);

        return mb_substr($title, 0, 31 - mb_strlen($suffix)) . $suffix;
    }

    /**
     * @param  array<int, array<int, scalar|null>>  $rows
     */
    private function autoSize(Worksheet $sheet, array $rows): void
    {
        $widest = 0;
        foreach ($rows as $row) {
            $widest = max($widest, count($row));
        }

        for ($column = 1; $column <= $widest; $column++) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }
    }

    private function render(Spreadsheet $spreadsheet): string
    {
        // The xlsx writer builds a zip archive, which needs a real path rather
        // than an in-memory stream.
        $path = tempnam(sys_get_temp_dir(), 'trakli-export-');

        if ($path === false) {
            throw new RuntimeException('Unable to allocate a temporary file for the export.');
        }

        try {
            (new Xlsx($spreadsheet))->save($path);

            return (string) file_get_contents($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
