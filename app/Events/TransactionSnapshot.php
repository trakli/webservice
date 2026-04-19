<?php

namespace App\Events;

use Carbon\CarbonImmutable;

final class TransactionSnapshot
{
    public function __construct(
        public readonly ?int $transactionId,
        public readonly int $userId,
        public readonly ?int $walletId,
        public readonly ?string $type,
        public readonly float $amount,
        public readonly ?CarbonImmutable $datetime,
        /** @var array<int> */
        public readonly array $categoryIds = [],
        /** @var array<int> */
        public readonly array $groupIds = [],
    ) {
    }
}
