<?php

namespace App\Types;

readonly class DuplicateMatch
{
    public function __construct(
        public int $transactionId,
        public string $matchType,
        public float $confidence,
        public ?float $transactionAmount = null,
        public ?string $transactionDescription = null,
        public ?string $transactionDate = null,
        public ?string $transactionType = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'match_type' => $this->matchType,
            'confidence' => $this->confidence,
            'transaction_amount' => $this->transactionAmount,
            'transaction_description' => $this->transactionDescription,
            'transaction_date' => $this->transactionDate,
            'transaction_type' => $this->transactionType,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            transactionId: (int) $data['transaction_id'],
            matchType: $data['match_type'],
            confidence: (float) $data['confidence'],
            transactionAmount: $data['transaction_amount'] ?? null,
            transactionDescription: $data['transaction_description'] ?? null,
            transactionDate: $data['transaction_date'] ?? null,
            transactionType: $data['transaction_type'] ?? null,
        );
    }
}
