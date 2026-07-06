<?php

namespace App\Contracts;

/**
 * A document processor that can surface the raw text/tables it extracted from
 * the source document, so a downstream reviewer can validate parsed values
 * (amount, date, currency) against the original document rather than trusting
 * a positional guess.
 */
interface ProvidesExtractionContext
{
    /**
     * The extracted document context from the most recent process() call, or
     * null when the processor has no textual context (e.g. structured CSV/XML).
     */
    public function lastExtractionContext(): ?string;
}
