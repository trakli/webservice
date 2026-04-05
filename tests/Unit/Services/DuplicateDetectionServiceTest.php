<?php

namespace Tests\Unit\Services;

use App\Models\Transaction;
use App\Models\User;
use App\Services\DuplicateDetectionService;
use App\Types\TransactionSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicateDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private DuplicateDetectionService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DuplicateDetectionService();
        $this->user = User::factory()->create();
    }

    public function test_match_same_amount_same_date_similar_description(): void
    {
        Transaction::factory()->withUserAndWallet($this->user)->create([
            'amount' => 100.00,
            'datetime' => '2025-01-15',
            'description' => 'Grocery Store Purchase',
            'type' => 'expense',
        ]);

        $suggestions = [
            new TransactionSuggestion(
                amount: 100.00,
                date: '2025-01-15',
                description: 'Grocery Store Purchase',
                type: 'expense',
            ),
        ];

        $results = $this->service->checkBatch($suggestions, $this->user);

        $this->assertCount(1, $results);
        $this->assertNotNull($results[0]);
        // Same amount + same date matches as near or exact
        $this->assertContains($results[0]->matchType, ['exact', 'near']);
        $this->assertGreaterThanOrEqual(0.8, $results[0]->confidence);
    }

    public function test_near_match_same_amount_date_within_3_days(): void
    {
        Transaction::factory()->withUserAndWallet($this->user)->create([
            'amount' => 200.00,
            'datetime' => '2025-01-15',
            'description' => 'Something completely different',
            'type' => 'expense',
        ]);

        // Use two suggestions so that min/max parsedDates produce distinct Carbon objects,
        // avoiding a Carbon mutability issue when there's only one suggestion date.
        $suggestions = [
            new TransactionSuggestion(
                amount: 200.00,
                date: '2025-01-17',
                description: 'A totally unrelated description',
                type: 'expense',
            ),
            new TransactionSuggestion(
                amount: 999.00,
                date: '2025-01-13',
                description: 'Dummy to widen date range',
                type: 'expense',
            ),
        ];

        $results = $this->service->checkBatch($suggestions, $this->user);

        $this->assertCount(2, $results);
        $this->assertNotNull($results[0]);
        $this->assertEquals('near', $results[0]->matchType);
        $this->assertEquals(0.8, $results[0]->confidence);
        $this->assertEquals(200.00, $results[0]->transactionAmount);
    }

    public function test_similar_match_amount_within_5_percent_date_within_3_days(): void
    {
        Transaction::factory()->withUserAndWallet($this->user)->create([
            'amount' => 100.00,
            'datetime' => '2025-01-15',
            'description' => 'Payment',
            'type' => 'expense',
        ]);

        // Use two suggestions to ensure the date range query encompasses both dates
        $suggestions = [
            new TransactionSuggestion(
                amount: 104.00,
                date: '2025-01-17',
                description: 'Something else entirely',
                type: 'expense',
            ),
            new TransactionSuggestion(
                amount: 999.00,
                date: '2025-01-13',
                description: 'Dummy to widen date range',
                type: 'expense',
            ),
        ];

        $results = $this->service->checkBatch($suggestions, $this->user);

        $this->assertCount(2, $results);
        $this->assertNotNull($results[0]);
        $this->assertEquals('similar', $results[0]->matchType);
        $this->assertEquals(0.5, $results[0]->confidence);
    }

    public function test_no_match_when_amount_and_date_differ_significantly(): void
    {
        Transaction::factory()->withUserAndWallet($this->user)->create([
            'amount' => 100.00,
            'datetime' => '2025-01-15',
            'description' => 'Old transaction',
            'type' => 'expense',
        ]);

        $suggestions = [
            new TransactionSuggestion(
                amount: 500.00,
                date: '2025-06-01',
                description: 'Completely new transaction',
                type: 'income',
            ),
        ];

        $results = $this->service->checkBatch($suggestions, $this->user);

        $this->assertCount(1, $results);
        $this->assertNull($results[0]);
    }

    public function test_empty_suggestions_returns_empty_array(): void
    {
        $results = $this->service->checkBatch([], $this->user);

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function test_null_date_in_suggestion_returns_null_match(): void
    {
        Transaction::factory()->withUserAndWallet($this->user)->create([
            'amount' => 100.00,
            'datetime' => '2025-01-15',
            'type' => 'expense',
        ]);

        $suggestions = [
            new TransactionSuggestion(
                amount: 100.00,
                date: null,
                type: 'expense',
            ),
        ];

        $results = $this->service->checkBatch($suggestions, $this->user);

        $this->assertCount(1, $results);
        $this->assertNull($results[0]);
    }

    public function test_null_amount_in_suggestion_returns_null_match(): void
    {
        Transaction::factory()->withUserAndWallet($this->user)->create([
            'amount' => 100.00,
            'datetime' => '2025-01-15',
            'type' => 'expense',
        ]);

        $suggestions = [
            new TransactionSuggestion(
                amount: null,
                date: '2025-01-15',
                type: 'expense',
            ),
        ];

        $results = $this->service->checkBatch($suggestions, $this->user);

        $this->assertCount(1, $results);
        $this->assertNull($results[0]);
    }

    public function test_no_match_when_date_exceeds_3_day_range(): void
    {
        Transaction::factory()->withUserAndWallet($this->user)->create([
            'amount' => 100.00,
            'datetime' => '2025-01-15',
            'description' => 'Same description',
            'type' => 'expense',
        ]);

        $suggestions = [
            new TransactionSuggestion(
                amount: 100.00,
                date: '2025-01-20',
                description: 'Same description',
                type: 'expense',
            ),
        ];

        $results = $this->service->checkBatch($suggestions, $this->user);

        $this->assertCount(1, $results);
        $this->assertNull($results[0]);
    }

    public function test_multiple_suggestions_checked_independently(): void
    {
        Transaction::factory()->withUserAndWallet($this->user)->create([
            'amount' => 100.00,
            'datetime' => '2025-01-15',
            'description' => 'Existing transaction',
            'type' => 'expense',
        ]);

        $suggestions = [
            new TransactionSuggestion(
                amount: 100.00,
                date: '2025-01-15',
                description: 'Existing transaction',
                type: 'expense',
            ),
            new TransactionSuggestion(
                amount: 999.00,
                date: '2025-07-01',
                description: 'Brand new transaction',
                type: 'income',
            ),
        ];

        $results = $this->service->checkBatch($suggestions, $this->user);

        $this->assertCount(2, $results);
        $this->assertNotNull($results[0]);
        $this->assertContains($results[0]->matchType, ['exact', 'near']);
        $this->assertNull($results[1]);
    }

    public function test_no_existing_transactions_returns_all_null_matches(): void
    {
        $suggestions = [
            new TransactionSuggestion(
                amount: 50.00,
                date: '2025-01-15',
                type: 'expense',
            ),
        ];

        $results = $this->service->checkBatch($suggestions, $this->user);

        $this->assertCount(1, $results);
        $this->assertNull($results[0]);
    }
}
