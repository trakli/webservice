<?php

namespace App\Exports;

/**
 * A format-independent description of what an export contains. Builders produce
 * one of these; each Exporter renders it. Adding a format means adding one
 * Exporter, and every existing export gains it for free.
 */
class ExportDocument
{
    /**
     * @param  array<int, ExportSection>  $sections
     * @param  array<string, scalar|null>  $meta  Header lines (period, currency, generated at)
     * @param  array<int, string>  $notices  Caveats that must travel with the numbers
     */
    public function __construct(
        public readonly string $title,
        public readonly array $sections,
        public readonly array $meta = [],
        public readonly array $notices = [],
    ) {
    }
}
