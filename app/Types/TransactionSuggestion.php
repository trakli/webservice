<?php

namespace App\Types;

readonly class TransactionSuggestion
{
    /** @SuppressWarnings(PHPMD.ExcessiveParameterList) */
    public function __construct(
        public ?float $amount = null,
        public ?string $currency = null,
        public ?string $type = null,
        public ?string $party = null,
        public ?string $wallet = null,
        public ?string $category = null,
        public ?string $description = null,
        public ?string $date = null,
        public float $confidence = 1.0,
        public ?string $documentType = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'type' => $this->type,
            'party' => $this->party,
            'wallet' => $this->wallet,
            'category' => $this->category,
            'description' => $this->description,
            'date' => $this->date,
            'confidence' => $this->confidence,
            'document_type' => $this->documentType,
        ];
    }

    /**
     * Convert to the positional array format used by FileImportService::importTransaction().
     */
    public function toImportArray(): array
    {
        return [
            $this->amount ?? '',
            $this->currency ?? '',
            $this->type ?? '',
            $this->party ?? '',
            $this->wallet ?? '',
            $this->category ?? '',
            $this->description ?? '',
            $this->date ?? '',
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            amount: isset($data['amount']) ? (float) $data['amount'] : null,
            currency: $data['currency'] ?? null,
            type: $data['type'] ?? null,
            party: $data['party'] ?? null,
            wallet: $data['wallet'] ?? null,
            category: $data['category'] ?? null,
            description: $data['description'] ?? null,
            date: $data['date'] ?? null,
            confidence: (float) ($data['confidence'] ?? 1.0),
            documentType: $data['document_type'] ?? null,
        );
    }
}
