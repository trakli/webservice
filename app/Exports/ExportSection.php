<?php

namespace App\Exports;

/**
 * One block of an exported document: an optional set of label/value summary
 * lines followed by an optional table. A transaction export is a single section
 * with a table; a statement is several sections, some summary-only.
 */
class ExportSection
{
    /**
     * @param  array<int, string>  $columns
     * @param  iterable<int, array<int, scalar|null>>  $rows
     * @param  array<string, scalar|null>  $summary
     */
    public function __construct(
        public readonly string $heading,
        public readonly array $columns = [],
        public readonly iterable $rows = [],
        public readonly array $summary = [],
        public readonly ?string $note = null,
    ) {
    }

    public function hasTable(): bool
    {
        return ! empty($this->columns);
    }
}
