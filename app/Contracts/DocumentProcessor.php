<?php

namespace App\Contracts;

use App\Models\User;
use App\Types\TransactionSuggestion;
use Illuminate\Http\UploadedFile;

interface DocumentProcessor
{
    /**
     * Determine if this processor can handle the given file type.
     */
    public function supports(string $mimeType, string $extension): bool;

    /**
     * Process a file and return an array of transaction suggestions.
     *
     * @return TransactionSuggestion[]
     */
    public function process(UploadedFile $file, User $user): array;
}
