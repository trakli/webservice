<?php

namespace App\Ai\Export;

/**
 * Renders a canvas document to GitHub-flavored Markdown. Markdown blocks pass
 * through verbatim; tables/kpis/charts become Markdown tables and lists so the
 * exported file is legible and reusable.
 */
class MarkdownDocumentExporter implements DocumentExporter
{
    public function key(): string
    {
        return 'md';
    }

    public function mimeType(): string
    {
        return 'text/markdown';
    }

    public function extension(): string
    {
        return 'md';
    }

    public function export(array $blocks, string $title): string
    {
        $parts = ['# ' . $this->clean($title)];

        foreach ($blocks as $block) {
            $rendered = $this->block(is_array($block) ? $block : []);
            if ($rendered !== '') {
                $parts[] = $rendered;
            }
        }

        return implode("\n\n", $parts) . "\n";
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function block(array $block): string
    {
        return match ($block['type'] ?? '') {
            'markdown' => trim((string) ($block['text'] ?? '')),
            'table' => $this->table($block),
            'kpi' => $this->kpi($block),
            'chart' => $this->chart($block),
            'canvas' => $this->export(is_array($block['blocks'] ?? null) ? $block['blocks'] : [], (string) ($block['title'] ?? 'Section')),
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function table(array $block): string
    {
        $rows = is_array($block['rows'] ?? null) ? $block['rows'] : [];
        if ($rows === []) {
            return '';
        }

        $columns = is_array($block['columns'] ?? null) && $block['columns'] !== []
            ? $block['columns']
            : array_keys((array) $rows[0]);

        $lines = [];
        if (! empty($block['title'])) {
            $lines[] = '## ' . $this->clean((string) $block['title']);
        }

        $lines[] = '| ' . implode(' | ', array_map([$this, 'humanize'], $columns)) . ' |';
        $lines[] = '| ' . implode(' | ', array_fill(0, count($columns), '---')) . ' |';

        foreach ($rows as $row) {
            $row = (array) $row;
            $cells = array_map(fn ($column) => $this->cell($row[$column] ?? ''), $columns);
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function kpi(array $block): string
    {
        $items = is_array($block['items'] ?? null) ? $block['items'] : [];
        if ($items === []) {
            return '';
        }

        $lines = [];
        if (! empty($block['title'])) {
            $lines[] = '## ' . $this->clean((string) $block['title']);
        }

        foreach ($items as $item) {
            $item = (array) $item;
            $value = $this->cell($item['value'] ?? '');
            if (! empty($item['currency'])) {
                $value .= ' ' . $item['currency'];
            } elseif (! empty($item['unit'])) {
                $value .= (string) $item['unit'];
            }
            $lines[] = '- **' . $this->clean((string) ($item['label'] ?? '')) . ':** ' . $value;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function chart(array $block): string
    {
        $title = $block['title'] ?? ($block['dataset_ref'] ?? 'Chart');
        $caption = '_' . $this->clean('Chart: ' . $this->humanize((string) $title)) . '_';

        // Represent the chart's data as a table when it is a list of rows.
        $data = $block['data'] ?? null;
        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            $table = $this->table(['rows' => $data]);

            return $table === '' ? $caption : $caption . "\n\n" . $table;
        }

        return $caption;
    }

    private function cell(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(', ', $value);
        }
        if (is_bool($value)) {
            $value = $value ? 'yes' : 'no';
        }

        return str_replace(['|', "\n"], ['\\|', ' '], (string) $value);
    }

    private function humanize(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }

    private function clean(string $text): string
    {
        return trim($text);
    }
}
