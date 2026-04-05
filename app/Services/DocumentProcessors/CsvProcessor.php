<?php

namespace App\Services\DocumentProcessors;

use App\Contracts\DocumentProcessor;
use App\Enums\TransactionType;
use App\Models\User;
use App\Services\FileImportService;
use App\Types\TransactionSuggestion;
use Illuminate\Http\UploadedFile;

class CsvProcessor implements DocumentProcessor
{
    public function __construct(
        private FileImportService $fileImportService,
    ) {
    }

    public function supports(string $mimeType, string $extension): bool
    {
        return $extension === 'csv' || $mimeType === 'text/csv';
    }

    /**
     * @return TransactionSuggestion[]
     */
    public function process(UploadedFile $file, User $user): array
    {
        $suggestions = [];
        $path = $file->getRealPath();

        if (! $path) {
            return $suggestions;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return $suggestions;
        }

        $isFirstRow = true;
        while (($data = fgetcsv($handle)) !== false) {
            if ($isFirstRow) {
                $isFirstRow = false;

                continue;
            }

            $transactionType = isset($data[2]) ? strtolower(trim($data[2])) : null;

            // Skip transfer rows for now — the suggestion flow handles income/expense
            if (! in_array($transactionType, [TransactionType::EXPENSE->value, TransactionType::INCOME->value])) {
                $transactionType = null;
            }

            $date = $data[7] ?? null;
            $confidence = 1.0;

            if ($date && ! $this->fileImportService->isValidDate($date)) {
                $confidence = 0.3;
            }

            $suggestions[] = new TransactionSuggestion(
                amount: isset($data[0]) ? (float) $data[0] : null,
                currency: $data[1] ?? null,
                type: $transactionType,
                party: $data[3] ?? null,
                wallet: $data[4] ?? null,
                category: $data[5] ?? null,
                description: $data[6] ?? null,
                date: $date,
                confidence: $confidence,
                documentType: 'csv',
            );
        }

        fclose($handle);

        return $suggestions;
    }
}
