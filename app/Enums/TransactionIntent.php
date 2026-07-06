<?php

namespace App\Enums;

enum TransactionIntent: string
{
    case REGULAR = 'regular';
    case LOAN_RECEIVED = 'loan_received';
    case LOAN_REPAYMENT = 'loan_repayment';
    case DEBT_OWED = 'debt_owed';
    case DEBT_SETTLED = 'debt_settled';
    case INVESTMENT_BUY = 'investment_buy';
    case INVESTMENT_RETURN = 'investment_return';
    case GIFT = 'gift';
    case FEE = 'fee';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    public function bucket(): string
    {
        return match ($this) {
            self::REGULAR => 'regular',
            self::LOAN_RECEIVED, self::LOAN_REPAYMENT => 'loan',
            self::DEBT_OWED, self::DEBT_SETTLED => 'debt',
            self::INVESTMENT_BUY, self::INVESTMENT_RETURN => 'investment',
            self::GIFT => 'gift',
            self::FEE => 'fee',
        };
    }

    /**
     * +1 when the movement raises net worth (money or asset in), -1 when it
     * lowers it (money or asset out).
     */
    public function sign(): int
    {
        return match ($this) {
            self::LOAN_REPAYMENT, self::DEBT_SETTLED, self::INVESTMENT_BUY, self::FEE => -1,
            default => 1,
        };
    }

    public function isRegular(): bool
    {
        return $this === self::REGULAR;
    }

    public function isLoanOrDebt(): bool
    {
        return in_array($this->bucket(), ['loan', 'debt'], true);
    }

    public function isInvestment(): bool
    {
        return $this->bucket() === 'investment';
    }
}
