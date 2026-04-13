<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\SuggestionEnricher;
use App\Types\TransactionSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

class SuggestionEnricherTest extends TestCase
{
    use RefreshDatabase;

    private SuggestionEnricher $enricher;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enricher = new SuggestionEnricher();
        $this->user = User::factory()->create();
    }

    public function test_empty_suggestions_returns_empty(): void
    {
        $result = $this->enricher->enrich([], $this->user);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_enrichment_merges_wallet_category_party_from_llm(): void
    {
        $this->user->wallets()->create([
            'name' => 'Main Checking',
            'currency' => 'USD',
            'type' => 'bank',
        ]);
        $this->user->categories()->create([
            'name' => 'Groceries',
            'type' => 'expense',
        ]);
        $this->user->parties()->create([
            'name' => 'SuperMart',
            'type' => 'business',
        ]);

        $llmResponse = json_encode([
            [
                'wallet' => 'Main Checking',
                'category' => 'Groceries',
                'party' => 'SuperMart',
                'type' => 'expense',
                'description' => 'Weekly groceries',
            ],
        ]);

        Prism::fake([
            new TextResponse(
                steps: collect([]),
                text: $llmResponse,
                finishReason: FinishReason::Stop,
                toolCalls: [],
                toolResults: [],
                usage: new Usage(0, 0),
                meta: new Meta('fake', 'fake'),
                messages: collect([]),
            ),
        ]);

        $suggestions = [
            new TransactionSuggestion(
                amount: 55.00,
                currency: 'USD',
                type: 'expense',
                party: 'SUPRMRT #123',
                description: 'SUPRMRT #123 Purchase',
                date: '2025-01-15',
                confidence: 0.8,
            ),
        ];

        $result = $this->enricher->enrich($suggestions, $this->user);

        $this->assertCount(1, $result);
        $this->assertEquals('Main Checking', $result[0]->wallet);
        $this->assertEquals('Groceries', $result[0]->category);
        $this->assertEquals('SuperMart', $result[0]->party);
        $this->assertEquals('Weekly groceries', $result[0]->description);
    }

    public function test_enrichment_preserves_original_dates_and_amounts(): void
    {
        $this->user->wallets()->create([
            'name' => 'Wallet',
            'currency' => 'USD',
            'type' => 'cash',
        ]);

        $llmResponse = json_encode([
            [
                'wallet' => 'Wallet',
                'category' => 'Food',
                'party' => 'Store',
                'type' => 'expense',
                'description' => 'Cleaned description',
            ],
        ]);

        Prism::fake([
            new TextResponse(
                steps: collect([]),
                text: $llmResponse,
                finishReason: FinishReason::Stop,
                toolCalls: [],
                toolResults: [],
                usage: new Usage(0, 0),
                meta: new Meta('fake', 'fake'),
                messages: collect([]),
            ),
        ]);

        $suggestions = [
            new TransactionSuggestion(
                amount: 99.99,
                currency: 'EUR',
                date: '2025-03-20',
                confidence: 0.9,
                documentType: 'csv',
            ),
        ];

        $result = $this->enricher->enrich($suggestions, $this->user);

        $this->assertCount(1, $result);
        $this->assertEquals(99.99, $result[0]->amount);
        $this->assertEquals('USD', $result[0]->currency);
        $this->assertEquals('2025-03-20', $result[0]->date);
        $this->assertEquals(0.9, $result[0]->confidence);
        $this->assertEquals('csv', $result[0]->documentType);
    }

    public function test_graceful_handling_when_llm_fails(): void
    {
        $this->user->wallets()->create([
            'name' => 'Wallet',
            'currency' => 'USD',
            'type' => 'cash',
        ]);

        // Fake Prism to throw an exception
        Prism::fake([]);

        // The fake with empty responses will return an empty text, which will
        // fail JSON parsing. Let's instead make the LLM return invalid JSON.
        Prism::fake([
            new TextResponse(
                steps: collect([]),
                text: 'THIS IS NOT JSON',
                finishReason: FinishReason::Stop,
                toolCalls: [],
                toolResults: [],
                usage: new Usage(0, 0),
                meta: new Meta('fake', 'fake'),
                messages: collect([]),
            ),
        ]);

        $suggestions = [
            new TransactionSuggestion(
                amount: 50.00,
                currency: 'USD',
                type: 'expense',
                party: 'Original Party',
                description: 'Original description',
                date: '2025-01-01',
            ),
        ];

        $result = $this->enricher->enrich($suggestions, $this->user);

        $this->assertCount(1, $result);
        $this->assertEquals('Original Party', $result[0]->party);
        $this->assertEquals('Original description', $result[0]->description);
        $this->assertEquals(50.00, $result[0]->amount);
    }

    public function test_llm_response_count_mismatch_returns_originals(): void
    {
        $this->user->wallets()->create([
            'name' => 'Wallet',
            'currency' => 'USD',
            'type' => 'cash',
        ]);

        // LLM returns 1 item but we send 2 suggestions
        $llmResponse = json_encode([
            [
                'wallet' => 'Wallet',
                'category' => 'Food',
                'party' => 'Store',
                'type' => 'expense',
                'description' => 'Desc',
            ],
        ]);

        Prism::fake([
            new TextResponse(
                steps: collect([]),
                text: $llmResponse,
                finishReason: FinishReason::Stop,
                toolCalls: [],
                toolResults: [],
                usage: new Usage(0, 0),
                meta: new Meta('fake', 'fake'),
                messages: collect([]),
            ),
        ]);

        $suggestions = [
            new TransactionSuggestion(
                amount: 10.00,
                currency: 'USD',
                type: 'expense',
                description: 'First',
                date: '2025-01-01',
            ),
            new TransactionSuggestion(
                amount: 20.00,
                currency: 'USD',
                type: 'expense',
                description: 'Second',
                date: '2025-01-02',
            ),
        ];

        $result = $this->enricher->enrich($suggestions, $this->user);

        $this->assertCount(2, $result);
        $this->assertEquals('First', $result[0]->description);
        $this->assertEquals('Second', $result[1]->description);
    }

    public function test_enrichment_with_no_user_entities_still_calls_llm(): void
    {
        // User has no wallets, categories, or parties
        $llmResponse = json_encode([
            [
                'wallet' => 'Personal Wallet',
                'category' => 'Dining',
                'party' => 'Restaurant',
                'type' => 'expense',
                'description' => 'Dinner',
            ],
        ]);

        Prism::fake([
            new TextResponse(
                steps: collect([]),
                text: $llmResponse,
                finishReason: FinishReason::Stop,
                toolCalls: [],
                toolResults: [],
                usage: new Usage(0, 0),
                meta: new Meta('fake', 'fake'),
                messages: collect([]),
            ),
        ]);

        $suggestions = [
            new TransactionSuggestion(
                amount: 30.00,
                currency: 'USD',
                description: 'DNR at rest',
                date: '2025-02-01',
            ),
        ];

        $result = $this->enricher->enrich($suggestions, $this->user);

        $this->assertCount(1, $result);
        $this->assertEquals('Personal Wallet', $result[0]->wallet);
        $this->assertEquals('Dining', $result[0]->category);
        $this->assertEquals('expense', $result[0]->type);
    }

    public function test_enrichment_strips_markdown_fences_from_llm_response(): void
    {
        $this->user->wallets()->create([
            'name' => 'Wallet',
            'currency' => 'USD',
            'type' => 'cash',
        ]);

        $llmResponse = "```json\n" . json_encode([
            [
                'wallet' => 'Wallet',
                'category' => 'Travel',
                'party' => 'Airlines',
                'type' => 'expense',
                'description' => 'Flight ticket',
            ],
        ]) . "\n```";

        Prism::fake([
            new TextResponse(
                steps: collect([]),
                text: $llmResponse,
                finishReason: FinishReason::Stop,
                toolCalls: [],
                toolResults: [],
                usage: new Usage(0, 0),
                meta: new Meta('fake', 'fake'),
                messages: collect([]),
            ),
        ]);

        $suggestions = [
            new TransactionSuggestion(
                amount: 500.00,
                currency: 'USD',
                description: 'AIRLINE TKT',
                date: '2025-04-01',
            ),
        ];

        $result = $this->enricher->enrich($suggestions, $this->user);

        $this->assertCount(1, $result);
        $this->assertEquals('Wallet', $result[0]->wallet);
        $this->assertEquals('Flight ticket', $result[0]->description);
    }
}
