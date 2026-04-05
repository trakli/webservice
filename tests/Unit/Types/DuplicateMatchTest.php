<?php

namespace Tests\Unit\Types;

use App\Types\DuplicateMatch;
use PHPUnit\Framework\TestCase;

class DuplicateMatchTest extends TestCase
{
    public function test_to_array_returns_correct_structure(): void
    {
        $match = new DuplicateMatch(
            transactionId: 42,
            matchType: 'exact',
            confidence: 1.0,
            transactionAmount: 99.99,
            transactionDescription: 'Grocery store',
            transactionDate: '2025-01-15',
            transactionType: 'expense',
        );

        $array = $match->toArray();

        $this->assertEquals([
            'transaction_id' => 42,
            'match_type' => 'exact',
            'confidence' => 1.0,
            'transaction_amount' => 99.99,
            'transaction_description' => 'Grocery store',
            'transaction_date' => '2025-01-15',
            'transaction_type' => 'expense',
        ], $array);
    }

    public function test_from_array_round_trips_correctly(): void
    {
        $original = new DuplicateMatch(
            transactionId: 7,
            matchType: 'near',
            confidence: 0.8,
            transactionAmount: 250.00,
            transactionDescription: 'Monthly rent',
            transactionDate: '2025-02-01',
            transactionType: 'expense',
        );

        $restored = DuplicateMatch::fromArray($original->toArray());

        $this->assertEquals($original->transactionId, $restored->transactionId);
        $this->assertEquals($original->matchType, $restored->matchType);
        $this->assertEquals($original->confidence, $restored->confidence);
        $this->assertEquals($original->transactionAmount, $restored->transactionAmount);
        $this->assertEquals($original->transactionDescription, $restored->transactionDescription);
        $this->assertEquals($original->transactionDate, $restored->transactionDate);
        $this->assertEquals($original->transactionType, $restored->transactionType);
    }

    public function test_nullable_fields_default_to_null(): void
    {
        $match = new DuplicateMatch(
            transactionId: 1,
            matchType: 'similar',
            confidence: 0.5,
        );

        $this->assertNull($match->transactionAmount);
        $this->assertNull($match->transactionDescription);
        $this->assertNull($match->transactionDate);
        $this->assertNull($match->transactionType);
    }

    public function test_to_array_includes_null_optional_fields(): void
    {
        $match = new DuplicateMatch(
            transactionId: 1,
            matchType: 'similar',
            confidence: 0.5,
        );

        $array = $match->toArray();

        $this->assertArrayHasKey('transaction_amount', $array);
        $this->assertArrayHasKey('transaction_description', $array);
        $this->assertArrayHasKey('transaction_date', $array);
        $this->assertArrayHasKey('transaction_type', $array);
        $this->assertNull($array['transaction_amount']);
        $this->assertNull($array['transaction_description']);
        $this->assertNull($array['transaction_date']);
        $this->assertNull($array['transaction_type']);
    }

    public function test_from_array_casts_types_correctly(): void
    {
        $match = DuplicateMatch::fromArray([
            'transaction_id' => '42',
            'match_type' => 'exact',
            'confidence' => '0.95',
            'transaction_amount' => null,
        ]);

        $this->assertIsInt($match->transactionId);
        $this->assertEquals(42, $match->transactionId);
        $this->assertIsFloat($match->confidence);
        $this->assertEquals(0.95, $match->confidence);
    }
}
