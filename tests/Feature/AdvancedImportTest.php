<?php

namespace Tests\Feature;

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

    public function test_confirm_creates_transactions_from_accepted_suggestions(): void
    {
        $session = $this->user->importSessions()->create([
            'file_name' => 'test.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'ready',
            'suggestions' => [
                [
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
                ],
                [
                    'amount' => 200.00,
                    'currency' => 'EUR',
                    'type' => 'income',
                    'party' => 'Employer',
                    'wallet' => 'Savings',
                    'category' => 'Salary',
                    'description' => 'Monthly pay',
                    'date' => '2025-01-02',
                    'confidence' => 1.0,
                    'document_type' => 'csv',
                    'duplicate' => null,
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 0],
                ['index' => 1],
            ],
        ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(2, $data['created_count']);
        $this->assertEmpty($data['errors']);

        // Verify the session status was updated
        $session->refresh();
        $this->assertEquals('confirmed', $session->status);

        // Verify transactions were created
        $this->assertEquals(2, $this->user->transactions()->count());
    }

    public function test_confirm_rejects_already_confirmed_sessions(): void
    {
        $session = $this->user->importSessions()->create([
            'file_name' => 'test.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'confirmed',
            'suggestions' => [
                [
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
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 0],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_confirm_returns_404_for_other_users_session(): void
    {
        $otherUser = User::factory()->create();

        $session = $otherUser->importSessions()->create([
            'file_name' => 'test.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'ready',
            'suggestions' => [
                [
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
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 0],
            ],
        ]);

        $response->assertStatus(404);
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

    public function test_confirm_with_user_edits_overrides_suggestion_fields(): void
    {
        $session = $this->user->importSessions()->create([
            'file_name' => 'test.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'ready',
            'suggestions' => [
                [
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
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                [
                    'index' => 0,
                    'amount' => 150.00,
                    'description' => 'Edited description',
                ],
            ],
        ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(1, $data['created_count']);

        // Verify the transaction was created with the edited amount
        $transaction = $this->user->transactions()->first();
        $this->assertEquals(150.00, $transaction->amount);
        $this->assertEquals('Edited description', $transaction->description);
    }

    public function test_confirm_with_invalid_suggestion_index_returns_error(): void
    {
        $session = $this->user->importSessions()->create([
            'file_name' => 'test.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'ready',
            'suggestions' => [
                [
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
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                ['index' => 99],
            ],
        ]);

        // Should still return 206 because errors occurred but session is confirmed
        $response->assertStatus(206);

        $data = $response->json('data');
        $this->assertEquals(0, $data['created_count']);
        $this->assertNotEmpty($data['errors']);
    }

    public function test_confirm_with_date_override(): void
    {
        $session = $this->user->importSessions()->create([
            'file_name' => 'test.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'ready',
            'suggestions' => [
                [
                    'amount' => 75.00,
                    'currency' => 'USD',
                    'type' => 'expense',
                    'party' => 'Store',
                    'wallet' => 'Checking',
                    'category' => 'Food',
                    'description' => 'Groceries',
                    'date' => '2025-01-01',
                    'confidence' => 1.0,
                    'document_type' => 'csv',
                    'duplicate' => null,
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                [
                    'index' => 0,
                    'date' => '2025-06-15',
                    'amount' => 80.00,
                ],
            ],
        ]);

        $response->assertStatus(200);

        $transaction = $this->user->transactions()->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(80.00, $transaction->amount);
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

    public function test_sessions_are_scoped_to_authenticated_user(): void
    {
        $otherUser = User::factory()->create();

        // Create sessions for both users
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

        // User should only see their own session
        $response = $this->actingAs($this->user)->getJson('/api/v1/import/sessions');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('my-file.csv', $data[0]['file_name']);
    }

    public function test_confirm_requires_accepted_array(): void
    {
        $session = $this->user->importSessions()->create([
            'file_name' => 'test.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'ready',
            'suggestions' => [],
        ]);

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

    public function test_confirm_with_type_override(): void
    {
        $session = $this->user->importSessions()->create([
            'file_name' => 'test.csv',
            'file_type' => 'csv',
            'document_type' => null,
            'processor' => 'CsvProcessor',
            'status' => 'ready',
            'suggestions' => [
                [
                    'amount' => 100.00,
                    'currency' => 'USD',
                    'type' => 'expense',
                    'party' => 'Store',
                    'wallet' => 'Checking',
                    'category' => 'Food',
                    'description' => 'Refund',
                    'date' => '2025-01-01',
                    'confidence' => 1.0,
                    'document_type' => 'csv',
                    'duplicate' => null,
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/import/confirm', [
            'session_id' => $session->id,
            'accepted' => [
                [
                    'index' => 0,
                    'type' => 'income',
                ],
            ],
        ]);

        $response->assertStatus(200);

        $transaction = $this->user->transactions()->first();
        $this->assertNotNull($transaction);
        $this->assertEquals('income', $transaction->type);
    }
}
