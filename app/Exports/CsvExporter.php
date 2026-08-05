<?php

namespace App\Exports;

class CsvExporter implements Exporter
{
    public function key(): string
    {
        return 'csv';
    }

    public function mimeType(): string
    {
        return 'text/csv';
    }

    public function extension(): string
    {
        return 'csv';
    }

    public function maxRows(): int
    {
        return 20000;
    }

    public function export(ExportDocument $document): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [$document->title]);

        foreach ($document->meta as $label => $value) {
            fputcsv($handle, [$label, $this->scalar($value)]);
        }

        foreach ($document->notices as $notice) {
            fputcsv($handle, [$notice]);
        }

        foreach ($document->sections as $section) {
            fputcsv($handle, []);
            fputcsv($handle, [$section->heading]);

            if ($section->note !== null) {
                fputcsv($handle, [$section->note]);
            }

            foreach ($section->summary as $label => $value) {
                fputcsv($handle, [$label, $this->scalar($value)]);
            }

            if (! $section->hasTable()) {
                continue;
            }

            fputcsv($handle, $section->columns);

            foreach ($section->rows as $row) {
                fputcsv($handle, array_map(fn ($cell) => $this->scalar($cell), $row));
            }
        }

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        // Excel reads a UTF-8 CSV as the local codepage unless it sees a BOM,
        // which mangles currency symbols and accented category names.
        return "\u{FEFF}" . $contents;
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return (string) ($value ?? '');
    }
}
