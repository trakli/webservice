<?php

namespace Tests\Unit\Types;

use App\Types\TransactionSuggestion;
use PHPUnit\Framework\TestCase;

class TransactionSuggestionTest extends TestCase
{
    public function test_to_array_returns_correct_structure(): void
    {
        $suggestion = new TransactionSuggestion(
            amount: 99.99,
            currency: 'USD',
            type: 'expense',
            party: 'Amazon',
            wallet: 'Checking',
            category: 'Shopping',
            description: 'Online purchase',
            date: '2025-01-15',
            confidence: 0.95,
            documentType: 'csv',
        );

        $array = $suggestion->toArray();

        $this->assertEquals([
            'amount' => 99.99,
            'currency' => 'USD',
            'type' => 'expense',
            'party' => 'Amazon',
            'wallet' => 'Checking',
            'category' => 'Shopping',
            'description' => 'Online purchase',
            'date' => '2025-01-15',
            'confidence' => 0.95,
            'document_type' => 'csv',
        ], $array);
    }

    public function test_from_array_round_trips_correctly(): void
    {
        $original = new TransactionSuggestion(
            amount: 50.0,
            currency: 'EUR',
            type: 'income',
            party: 'Employer',
            wallet: 'Savings',
            category: 'Salary',
            description: 'Monthly salary',
            date: '2025-03-01',
            confidence: 1.0,
            documentType: 'pdf',
        );

        $restored = TransactionSuggestion::fromArray($original->toArray());

        $this->assertEquals($original->amount, $restored->amount);
        $this->assertEquals($original->currency, $restored->currency);
        $this->assertEquals($original->type, $restored->type);
        $this->assertEquals($original->party, $restored->party);
        $this->assertEquals($original->wallet, $restored->wallet);
        $this->assertEquals($original->category, $restored->category);
        $this->assertEquals($original->description, $restored->description);
        $this->assertEquals($original->date, $restored->date);
        $this->assertEquals($original->confidence, $restored->confidence);
        $this->assertEquals($original->documentType, $restored->documentType);
    }

    public function test_to_import_array_returns_positional_array_in_correct_order(): void
    {
        $suggestion = new TransactionSuggestion(
            amount: 100.0,
            currency: 'USD',
            type: 'expense',
            party: 'Store',
            wallet: 'Cash',
            category: 'Food',
            description: 'Groceries',
            date: '2025-06-01',
        );

        $importArray = $suggestion->toImportArray();

        $this->assertCount(8, $importArray);
        $this->assertEquals(100.0, $importArray[0]);  // amount
        $this->assertEquals('USD', $importArray[1]);   // currency
        $this->assertEquals('expense', $importArray[2]); // type
        $this->assertEquals('Store', $importArray[3]); // party
        $this->assertEquals('Cash', $importArray[4]);  // wallet
        $this->assertEquals('Food', $importArray[5]);  // category
        $this->assertEquals('Groceries', $importArray[6]); // description
        $this->assertEquals('2025-06-01', $importArray[7]); // date
    }

    public function test_to_import_array_uses_empty_strings_for_null_fields(): void
    {
        $suggestion = new TransactionSuggestion(
            amount: null,
            currency: null,
            type: null,
        );

        $importArray = $suggestion->toImportArray();

        $this->assertCount(8, $importArray);
        $this->assertEquals('', $importArray[0]);
        $this->assertEquals('', $importArray[1]);
        $this->assertEquals('', $importArray[2]);
        $this->assertEquals('', $importArray[3]);
        $this->assertEquals('', $importArray[4]);
        $this->assertEquals('', $importArray[5]);
        $this->assertEquals('', $importArray[6]);
        $this->assertEquals('', $importArray[7]);
    }

    public function test_nullable_fields_default_correctly(): void
    {
        $suggestion = new TransactionSuggestion();

        $this->assertNull($suggestion->amount);
        $this->assertNull($suggestion->currency);
        $this->assertNull($suggestion->type);
        $this->assertNull($suggestion->party);
        $this->assertNull($suggestion->wallet);
        $this->assertNull($suggestion->category);
        $this->assertNull($suggestion->description);
        $this->assertNull($suggestion->date);
        $this->assertEquals(1.0, $suggestion->confidence);
        $this->assertNull($suggestion->documentType);
    }

    public function test_from_array_handles_missing_keys_gracefully(): void
    {
        $suggestion = TransactionSuggestion::fromArray([
            'amount' => 42.0,
        ]);

        $this->assertEquals(42.0, $suggestion->amount);
        $this->assertNull($suggestion->currency);
        $this->assertNull($suggestion->type);
        $this->assertNull($suggestion->party);
        $this->assertNull($suggestion->wallet);
        $this->assertNull($suggestion->category);
        $this->assertNull($suggestion->description);
        $this->assertNull($suggestion->date);
        $this->assertEquals(1.0, $suggestion->confidence);
        $this->assertNull($suggestion->documentType);
    }

    public function test_from_array_casts_amount_to_float(): void
    {
        $suggestion = TransactionSuggestion::fromArray([
            'amount' => '123.45',
        ]);

        $this->assertIsFloat($suggestion->amount);
        $this->assertEquals(123.45, $suggestion->amount);
    }

    public function test_from_array_casts_confidence_to_float(): void
    {
        $suggestion = TransactionSuggestion::fromArray([
            'confidence' => '0.75',
        ]);

        $this->assertIsFloat($suggestion->confidence);
        $this->assertEquals(0.75, $suggestion->confidence);
    }
}
