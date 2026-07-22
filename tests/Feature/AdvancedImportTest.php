<?php

namespace Tests\Feature;

use App\Enums\TransactionIntent;
use App\Enums\TransactionType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdvancedImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_analyze_with_csv_creates_session_with_analyzing_status(): void
    {
        Storage::fake('local');
        Queue::fake();

        $csv = "amount,currency,type,party,wallet,category,description,date\n"
            . "100,USD,expense,Store,Wallet,Food,Lunch,2025-01-01\n";

        $file = UploadedFile::fake()->createWithContent('test.csv', $csv);

        $response = $this->actingAs($this->user)->post('/api/v1/import/analyze', [
            'file' => $file,
        ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals('analyzing', $data['status']);
        $this->assertEquals('test.csv', $data['file_name']);
        $this->assertEquals('csv', $data['file_type']);
    }

    public function test_analyze_rejects_unsupported_file_types(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('document.txt', 100, 'text/plain');

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/analyze', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
    }

    public function test_analyze_requires_authentication(): void
    {
        $csv = "amount,currency,type,party,wallet,category,description,date\n";
        $file = UploadedFile::fake()->createWithContent('test.csv', $csv);

        $response = $this->postJson('/api/v1/import/analyze', [
            'file' => $file,
        ]);

        $response->assertStatus(401);
    }

    public function test_analyze_with_document_type_parameter(): void
    {
        Storage::fake('local');
        Queue::fake();

        $csv = "amount,currency,type,party,wallet,category,description,date\n"
            . "100,USD,expense,Store,Wallet,Food,Lunch,2025-01-01\n";

        $file = UploadedFile::fake()->createWithContent('statement.csv', $csv);

        $response = $this->actingAs($this->user)->post('/api/v1/import/analyze', [
            'file' => $file,
            'document_type' => 'bank_statement',
        ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals('bank_statement', $data['document_type']);
        $this->assertEquals('analyzing', $data['status']);
    }

    public function test_analyze_rejects_invalid_document_type(): void
    {
        Storage::fake('local');

        $csv = "amount,currency,type,party,wallet,category,description,date\n"
            . "100,USD,expense,Store,Wallet,Food,Lunch,2025-01-01\n";

        $file = UploadedFile::fake()->createWithContent('test.csv', $csv);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/analyze', [
            'file' => $file,
            'document_type' => 'invalid_type',
        ]);

        $response->assertStatus(422);
    }

    public function test_get_sessions_returns_user_sessions(): void
    {
        $this->user->importSessions()->create([
            'file_name' => 'first.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'ready',
            'suggestions' => [],
        ]);

        $this->user->importSessions()->create([
            'file_name' => 'second.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'confirmed',
            'suggestions' => [],
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/import/sessions');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    public function test_get_sessions_does_not_return_other_users_sessions(): void
    {
        $otherUser = User::factory()->create();

        $otherUser->importSessions()->create([
            'file_name' => 'other.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'ready',
            'suggestions' => [],
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/import/sessions');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(0, $data);
    }

    public function test_get_session_returns_specific_session(): void
    {
        $session = $this->user->importSessions()->create([
            'file_name' => 'test.csv',
            'file_type' => 'csv',
            'document_type' => 'bank_statement',
            'processor' => 'CsvProcessor',
            'status' => 'ready',
            'suggestions' => [['amount' => 100]],
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/import/sessions/{$session->id}");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals('test.csv', $data['file_name']);
        $this->assertEquals('bank_statement', $data['document_type']);
        $this->assertEquals('ready', $data['status']);
    }

    public function test_get_session_returns_404_for_other_users_session(): void
    {
        $otherUser = User::factory()->create();

        $session = $otherUser->importSessions()->create([
            'file_name' => 'secret.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'ready',
            'suggestions' => [],
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/import/sessions/{$session->id}");

        $response->assertStatus(404);
    }

    public function test_get_session_returns_404_for_nonexistent_session(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/import/sessions/99999');

        $response->assertStatus(404);
    }

    public function test_delete_session_removes_the_owners_session(): void
    {
        $session = $this->user->importSessions()->create([
            'file_name' => 'test.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'failed',
            'suggestions' => [],
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/import/sessions/{$session->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('import_sessions', ['id' => $session->id]);
    }

    public function test_delete_session_returns_404_for_other_users_session(): void
    {
        $otherUser = User::factory()->create();

        $session = $otherUser->importSessions()->create([
            'file_name' => 'secret.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'ready',
            'suggestions' => [],
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/import/sessions/{$session->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('import_sessions', ['id' => $session->id]);
    }

    public function test_sessions_are_scoped_to_authenticated_user(): void
    {
        $otherUser = User::factory()->create();

        $this->user->importSessions()->create([
            'file_name' => 'my-file.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'ready',
            'suggestions' => [],
        ]);

        $otherUser->importSessions()->create([
            'file_name' => 'other-file.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'ready',
            'suggestions' => [],
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/import/sessions');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('my-file.csv', $data[0]['file_name']);
    }

    // ---------------------------------------------------------------------
    // /import/confirm — strict ID-based contract
    // ---------------------------------------------------------------------

    public function test_confirm_creates_transactions_from_accepted_suggestions(): void
    {
        $walletA = $this->user->wallets()->create(['name' => 'Checking', 'currency' => 'USD']);
        $walletB = $this->user->wallets()->create(['name' => 'Savings', 'currency' => 'EUR']);

        $session = $this->makeReadySession([
            $this->sampleSuggestion(['amount' => 100.00, 'type' => 'expense']),
            $this->sampleSuggestion(['amount' => 200.00, 'type' => 'income', 'currency' => 'EUR']),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 0, 'wallet_id' => $walletA->id],
                ['index' => 1, 'wallet_id' => $walletB->id],
            ],
        ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(2, $data['created_count']);
        $this->assertEmpty($data['errors']);

        $session->refresh();
        $this->assertEquals('confirmed', $session->status);

        $this->assertEquals(2, $this->user->transactions()->count());
    }

    public function test_confirm_creates_a_charges_expense_for_a_fee(): void
    {
        $wallet = $this->user->wallets()->create(['name' => 'MoMo', 'currency' => 'XAF']);

        $session = $this->makeReadySession([
            $this->sampleSuggestion(['amount' => 2000.00, 'type' => 'expense', 'currency' => 'XAF', 'fee' => 8.00]),
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [['index' => 0, 'wallet_id' => $wallet->id]],
        ])->assertStatus(200);

        // The transaction plus a separate fee expense.
        $this->assertEquals(2, $this->user->transactions()->count());

        $fee = $this->user->transactions()->where('intent', TransactionIntent::FEE->value)->first();
        $this->assertNotNull($fee);
        $this->assertEquals(8.00, (float) $fee->amount);
        $this->assertEquals(TransactionType::EXPENSE->value, $fee->type);
        $this->assertEquals($wallet->id, $fee->wallet_id);

        $charges = $this->user->parties()->where('name', 'Charges')->first();
        $this->assertNotNull($charges);
        $this->assertEquals($charges->id, $fee->party_id);

        // Unlinked by default.
        $this->assertNull($fee->metadata);
    }

    public function test_confirm_links_the_fee_to_its_transaction_on_request(): void
    {
        $wallet = $this->user->wallets()->create(['name' => 'MoMo', 'currency' => 'XAF']);

        $session = $this->makeReadySession([
            $this->sampleSuggestion(['amount' => 2000.00, 'type' => 'expense', 'currency' => 'XAF', 'fee' => 8.00]),
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [['index' => 0, 'wallet_id' => $wallet->id]],
            'link_fees' => true,
        ])->assertStatus(200);

        $main = $this->user->transactions()
            ->where('intent', '!=', TransactionIntent::FEE->value)
            ->where('amount', 2000.00)
            ->first();
        $fee = $this->user->transactions()->where('intent', TransactionIntent::FEE->value)->first();

        $this->assertNotNull($main);
        $this->assertNotNull($fee);
        $this->assertEquals($main->id, $fee->metadata['fee_of_transaction_id']);
    }

    public function test_confirm_accepts_party_id_and_category_id(): void
    {
        $wallet = $this->user->wallets()->create(['name' => 'Main', 'currency' => 'USD']);
        $party = $this->user->parties()->create(['name' => 'Employer Inc.']);
        $category = $this->user->categories()->create(['name' => 'Payroll', 'type' => 'income']);

        $session = $this->makeReadySession([
            $this->sampleSuggestion(['amount' => 2500.00, 'type' => 'income', 'party' => 'Ignored', 'category' => 'Ignored']),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                [
                    'index' => 0,
                    'wallet_id' => $wallet->id,
                    'party_id' => $party->id,
                    'category_id' => $category->id,
                ],
            ],
        ]);

        $response->assertStatus(200);

        $transaction = $this->user->transactions()->first();
        $this->assertNotNull($transaction);
        $this->assertEquals($wallet->id, $transaction->wallet_id);
        $this->assertEquals($party->id, $transaction->party_id);
        $this->assertEquals(1, $transaction->categories()->count());
        $this->assertEquals($category->id, $transaction->categories()->first()->id);

        // No new entities were created despite the suggestion's names pointing elsewhere.
        $this->assertEquals(1, $this->user->wallets()->count());
        $this->assertEquals(1, $this->user->parties()->count());
        $this->assertEquals(1, $this->user->categories()->count());
    }

    public function test_confirm_ignores_wallet_name_in_suggestion_metadata(): void
    {
        $realWallet = $this->user->wallets()->create(['name' => 'Travel Card', 'currency' => 'USD']);
        // Decoy wallet matching the suggestion's name must be ignored because IDs are the only input.
        $this->user->wallets()->create(['name' => 'Suggested Wallet', 'currency' => 'USD']);

        $session = $this->makeReadySession([
            $this->sampleSuggestion(['wallet' => 'Suggested Wallet']),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 0, 'wallet_id' => $realWallet->id],
            ],
        ]);

        $response->assertStatus(200);

        $transaction = $this->user->transactions()->first();
        $this->assertNotNull($transaction);
        $this->assertEquals($realWallet->id, $transaction->wallet_id);
    }

    public function test_confirm_rejects_wallet_id_owned_by_another_user(): void
    {
        $otherUser = User::factory()->create();
        $foreignWallet = $otherUser->wallets()->create(['name' => 'Foreign', 'currency' => 'USD']);

        $session = $this->makeReadySession([$this->sampleSuggestion()]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 0, 'wallet_id' => $foreignWallet->id],
            ],
        ]);

        $response->assertStatus(206);

        $data = $response->json('data');
        $this->assertEquals(0, $data['created_count']);
        $this->assertNotEmpty($data['errors']);
        $this->assertStringContainsString((string) $foreignWallet->id, $data['errors'][0]);

        $this->assertEquals(0, $this->user->transactions()->count());
        $this->assertEquals(0, $this->user->wallets()->count());
        $this->assertEquals(1, $otherUser->wallets()->count());
    }

    public function test_confirm_rejects_nonexistent_wallet_id(): void
    {
        $session = $this->makeReadySession([$this->sampleSuggestion()]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 0, 'wallet_id' => 999999],
            ],
        ]);

        $response->assertStatus(206);

        $data = $response->json('data');
        $this->assertEquals(0, $data['created_count']);
        $this->assertStringContainsString('999999', $data['errors'][0]);
    }

    public function test_confirm_rejects_party_id_owned_by_another_user(): void
    {
        $otherUser = User::factory()->create();
        $foreignParty = $otherUser->parties()->create(['name' => 'Foreign Party']);
        $wallet = $this->user->wallets()->create(['name' => 'Main', 'currency' => 'USD']);

        $session = $this->makeReadySession([$this->sampleSuggestion()]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 0, 'wallet_id' => $wallet->id, 'party_id' => $foreignParty->id],
            ],
        ]);

        $response->assertStatus(206);
        $this->assertEquals(0, $this->user->transactions()->count());
    }

    public function test_confirm_rejects_category_id_owned_by_another_user(): void
    {
        $otherUser = User::factory()->create();
        $foreignCategory = $otherUser->categories()->create(['name' => 'Foreign', 'type' => 'expense']);
        $wallet = $this->user->wallets()->create(['name' => 'Main', 'currency' => 'USD']);

        $session = $this->makeReadySession([$this->sampleSuggestion()]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 0, 'wallet_id' => $wallet->id, 'category_id' => $foreignCategory->id],
            ],
        ]);

        $response->assertStatus(206);
        $this->assertEquals(0, $this->user->transactions()->count());
    }

    public function test_confirm_errors_when_neither_wallet_id_nor_auto_create_is_provided(): void
    {
        $session = $this->makeReadySession([$this->sampleSuggestion()]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 0],
            ],
            // auto_create_wallets omitted -> defaults to false
        ]);

        $response->assertStatus(206);

        $data = $response->json('data');
        $this->assertEquals(0, $data['created_count']);
        $this->assertNotEmpty($data['errors']);

        $this->assertEquals(0, $this->user->transactions()->count());
        $this->assertEquals(0, $this->user->wallets()->count());
    }

    public function test_confirm_auto_creates_wallet_from_suggestion_when_flag_on(): void
    {
        $session = $this->makeReadySession([
            $this->sampleSuggestion(['wallet' => 'Fresh Wallet', 'currency' => 'USD']),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 0],
            ],
            'auto_create_wallets' => true,
        ]);

        $response->assertStatus(200);

        $this->assertEquals(1, $this->user->wallets()->count());
        $created = $this->user->wallets()->first();
        $this->assertEquals('Fresh Wallet', $created->name);
        $this->assertEquals('USD', $created->currency);

        $transaction = $this->user->transactions()->first();
        $this->assertNotNull($transaction);
        $this->assertEquals($created->id, $transaction->wallet_id);
    }

    public function test_confirm_auto_create_reuses_existing_wallet_matching_name_and_currency(): void
    {
        $existing = $this->user->wallets()->create(['name' => 'Main', 'currency' => 'USD']);

        $session = $this->makeReadySession([
            $this->sampleSuggestion(['wallet' => 'Main', 'currency' => 'USD']),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 0],
            ],
            'auto_create_wallets' => true,
        ]);

        $response->assertStatus(200);

        // No duplicate wallet — existing one was reused.
        $this->assertEquals(1, $this->user->wallets()->count());
        $this->assertEquals($existing->id, $this->user->transactions()->first()->wallet_id);
    }

    public function test_confirm_auto_create_wallet_requires_currency_in_suggestion(): void
    {
        $session = $this->makeReadySession([
            $this->sampleSuggestion(['wallet' => 'Nameless Wallet', 'currency' => null]),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 0],
            ],
            'auto_create_wallets' => true,
        ]);

        $response->assertStatus(206);
        $this->assertEquals(0, $this->user->wallets()->count());
        $this->assertEquals(0, $this->user->transactions()->count());
    }

    public function test_confirm_auto_creates_party_and_category_from_suggestion(): void
    {
        $wallet = $this->user->wallets()->create(['name' => 'Main', 'currency' => 'USD']);

        $session = $this->makeReadySession([
            $this->sampleSuggestion(['party' => 'New Vendor', 'category' => 'New Cat']),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 0, 'wallet_id' => $wallet->id],
            ],
            'auto_create_parties' => true,
            'auto_create_categories' => true,
        ]);

        $response->assertStatus(200);

        $this->assertEquals(1, $this->user->parties()->count());
        $this->assertEquals('New Vendor', $this->user->parties()->first()->name);
        $this->assertEquals(1, $this->user->categories()->count());
        $this->assertEquals('New Cat', $this->user->categories()->first()->name);

        $transaction = $this->user->transactions()->first();
        $this->assertEquals(1, $transaction->categories()->count());
    }

    public function test_confirm_wallet_id_wins_over_auto_create_flag(): void
    {
        $chosenWallet = $this->user->wallets()->create(['name' => 'Chosen', 'currency' => 'USD']);

        $session = $this->makeReadySession([
            $this->sampleSuggestion(['wallet' => 'Would Be Created', 'currency' => 'USD']),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 0, 'wallet_id' => $chosenWallet->id],
            ],
            'auto_create_wallets' => true,
        ]);

        $response->assertStatus(200);

        // Auto-create was on, but the explicit ID wins — no new wallet.
        $this->assertEquals(1, $this->user->wallets()->count());
        $this->assertEquals($chosenWallet->id, $this->user->transactions()->first()->wallet_id);
    }

    public function test_confirm_rejects_non_integer_wallet_id(): void
    {
        $session = $this->makeReadySession([$this->sampleSuggestion()]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 0, 'wallet_id' => 'not-an-int'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('accepted.0.wallet_id');
    }

    public function test_confirm_with_user_edits_overrides_suggestion_fields(): void
    {
        $wallet = $this->user->wallets()->create(['name' => 'Main', 'currency' => 'USD']);

        $session = $this->makeReadySession([$this->sampleSuggestion(['amount' => 100.00, 'description' => 'Lunch'])]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                [
                    'index' => 0,
                    'wallet_id' => $wallet->id,
                    'amount' => 150.00,
                    'description' => 'Edited description',
                ],
            ],
        ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(1, $data['created_count']);

        $transaction = $this->user->transactions()->first();
        $this->assertEquals(150.00, $transaction->amount);
        $this->assertEquals('Edited description', $transaction->description);
    }

    public function test_confirm_with_type_override(): void
    {
        $wallet = $this->user->wallets()->create(['name' => 'Main', 'currency' => 'USD']);

        $session = $this->makeReadySession([$this->sampleSuggestion(['type' => 'expense'])]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 0, 'wallet_id' => $wallet->id, 'type' => 'income'],
            ],
        ]);

        $response->assertStatus(200);

        $transaction = $this->user->transactions()->first();
        $this->assertEquals('income', $transaction->type);
    }

    public function test_confirm_with_date_override(): void
    {
        $wallet = $this->user->wallets()->create(['name' => 'Main', 'currency' => 'USD']);

        $session = $this->makeReadySession([$this->sampleSuggestion(['amount' => 75.00, 'date' => '2025-01-01'])]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                [
                    'index' => 0,
                    'wallet_id' => $wallet->id,
                    'date' => '2025-06-15',
                    'amount' => 80.00,
                ],
            ],
        ]);

        $response->assertStatus(200);

        $transaction = $this->user->transactions()->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(80.00, $transaction->amount);
        $this->assertEquals('2025-06-15', $transaction->datetime->format('Y-m-d'));
    }

    public function test_confirm_with_invalid_suggestion_index_returns_error(): void
    {
        $wallet = $this->user->wallets()->create(['name' => 'Main', 'currency' => 'USD']);

        $session = $this->makeReadySession([$this->sampleSuggestion()]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 99, 'wallet_id' => $wallet->id],
            ],
        ]);

        $response->assertStatus(206);

        $data = $response->json('data');
        $this->assertEquals(0, $data['created_count']);
        $this->assertNotEmpty($data['errors']);
    }

    public function test_confirm_rejects_already_confirmed_sessions(): void
    {
        $wallet = $this->user->wallets()->create(['name' => 'Main', 'currency' => 'USD']);

        $session = $this->user->importSessions()->create([
            'file_name' => 'test.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'confirmed',
            'suggestions' => [$this->sampleSuggestion()],
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 0, 'wallet_id' => $wallet->id],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_confirm_returns_404_for_other_users_session(): void
    {
        $otherUser = User::factory()->create();
        $wallet = $this->user->wallets()->create(['name' => 'Main', 'currency' => 'USD']);

        $session = $otherUser->importSessions()->create([
            'file_name' => 'test.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'ready',
            'suggestions' => [$this->sampleSuggestion()],
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 0, 'wallet_id' => $wallet->id],
            ],
        ]);

        $response->assertStatus(404);
    }

    public function test_confirm_requires_accepted_array(): void
    {
        $session = $this->makeReadySession([]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_confirm_requires_session_id(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'accepted' => [['index' => 0]],
        ]);

        $response->assertStatus(422);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    public function test_confirm_rejects_a_zero_amount_suggestion(): void
    {
        $wallet = $this->user->wallets()->create(['name' => 'Checking', 'currency' => 'USD']);

        $session = $this->makeReadySession([
            $this->sampleSuggestion(['amount' => 0, 'type' => 'expense']),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [['index' => 0, 'wallet_id' => $wallet->id]],
        ]);

        $response->assertStatus(206);

        $data = $response->json('data');
        $this->assertEquals(0, $data['created_count']);
        $this->assertNotEmpty($data['errors']);
        $this->assertEquals(0, $this->user->transactions()->count());
    }

    private function makeReadySession(array $suggestions): \App\Models\ImportSession
    {
        return $this->user->importSessions()->create([
            'file_name' => 'test.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'ready',
            'suggestions' => $suggestions,
        ]);
    }

    private function sampleSuggestion(array $overrides = []): array
    {
        return array_merge([
            'amount' => 100.00,
            'currency' => 'USD',
            'type' => 'expense',
            'party' => 'Store',
            'wallet' => 'Checking',
            'category' => 'Food',
            'description' => 'Lunch',
            'date' => '2025-01-01',
            'confidence' => 1.0,
            'document_type' => 'csv',
            'duplicate' => null,
        ], $overrides);
    }
}
