<?php

namespace Tests\Feature;

use App\Exports\ExporterManager;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private $wallet;

    private $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->wallet = $this->user->wallets()->create([
            'name' => 'Everyday',
            'balance' => 0,
            'currency' => 'USD',
        ]);

        $this->category = $this->user->categories()->create([
            'name' => 'Groceries',
            'type' => 'expense',
        ]);
    }

    private function createTransaction(array $overrides = []): array
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', array_merge([
            'type' => 'expense',
            'amount' => 100,
            'description' => 'Weekly shop',
            'wallet_id' => $this->wallet->id,
            'categories' => [$this->category->id],
            'datetime' => '2026-03-15T10:00:00.000Z',
        ], $overrides));

        $response->assertStatus(201);

        return $response->json('data');
    }

    public function test_transactions_export_defaults_to_csv(): void
    {
        $this->createTransaction();

        $response = $this->actingAs($this->user)->get('/api/v1/transactions/export');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString(
            'attachment; filename="transactions',
            (string) $response->headers->get('content-disposition')
        );

        $content = $response->getContent();
        $this->assertStringContainsString('Weekly shop', $content);
        $this->assertStringContainsString('Everyday', $content);
        $this->assertStringContainsString('Groceries', $content);
    }

    public function test_transactions_export_renders_xlsx_and_pdf(): void
    {
        $this->createTransaction();

        $xlsx = $this->actingAs($this->user)->get('/api/v1/transactions/export?format=xlsx');
        $xlsx->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', (string) $xlsx->headers->get('content-type'));
        // An xlsx file is a zip archive; anything else means the writer failed.
        $this->assertStringStartsWith('PK', $xlsx->getContent());

        $pdf = $this->actingAs($this->user)->get('/api/v1/transactions/export?format=pdf');
        $pdf->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_transactions_export_honours_the_same_filters_as_the_list(): void
    {
        $this->createTransaction(['description' => 'In range', 'datetime' => '2026-03-15T10:00:00.000Z']);
        $this->createTransaction(['description' => 'Out of range', 'datetime' => '2026-01-05T10:00:00.000Z']);

        $filters = 'date_from=2026-03-01&date_to=2026-03-31';

        $list = $this->actingAs($this->user)->getJson("/api/v1/transactions?{$filters}");
        $list->assertStatus(200);

        $export = $this->actingAs($this->user)->get("/api/v1/transactions/export?{$filters}");
        $export->assertStatus(200);

        $content = $export->getContent();
        $this->assertStringContainsString('In range', $content);
        $this->assertStringNotContainsString('Out of range', $content);

        $this->assertCount(1, $list->json('data.data'));
    }

    public function test_transactions_export_only_covers_the_authenticated_user(): void
    {
        $this->createTransaction(['description' => 'Mine']);

        $other = User::factory()->create();
        $otherWallet = $other->wallets()->create(['name' => 'Theirs', 'balance' => 0, 'currency' => 'USD']);
        $this->actingAs($other)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 50,
            'description' => 'Not mine',
            'wallet_id' => $otherWallet->id,
            'datetime' => '2026-03-15T10:00:00.000Z',
        ])->assertStatus(201);

        $content = $this->actingAs($this->user)->get('/api/v1/transactions/export')->getContent();

        $this->assertStringContainsString('Mine', $content);
        $this->assertStringNotContainsString('Not mine', $content);
    }

    public function test_export_requires_authentication(): void
    {
        $this->getJson('/api/v1/transactions/export')->assertStatus(401);
        $this->getJson('/api/v1/reports/export')->assertStatus(401);
    }

    public function test_unsupported_format_is_rejected(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/transactions/export?format=docx');

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertContains('csv', $response->json('errors.supported_formats'));
    }

    /**
     * dompdf lays the whole document out in memory, so it holds far less than
     * the other formats. The limit has to be enforced per format, or a large
     * selection exhausts the worker instead of returning an error.
     */
    public function test_the_row_limit_is_enforced_per_format(): void
    {
        $pdfLimit = app(ExporterManager::class)->for('pdf')->maxRows();

        // Bulk setup only; the export itself still goes over HTTP.
        Transaction::factory()->count($pdfLimit + 1)->create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'datetime' => now(),
        ]);

        $pdf = $this->actingAs($this->user)->getJson('/api/v1/transactions/export?format=pdf');
        $pdf->assertStatus(422);
        $pdf->assertJsonPath('errors.format', 'pdf');
        $pdf->assertJsonPath('errors.max_rows', $pdfLimit);
        $this->assertGreaterThan(
            $pdfLimit,
            $pdf->json('errors.format_limits.csv'),
            'the error should point at a format that holds more'
        );

        $csv = $this->actingAs($this->user)->get('/api/v1/transactions/export?format=csv');
        $csv->assertStatus(200);
    }

    public function test_report_export_renders_a_statement(): void
    {
        $this->createTransaction(['type' => 'income', 'amount' => 900, 'description' => 'Salary']);
        $this->createTransaction(['type' => 'expense', 'amount' => 300, 'description' => 'Rent']);

        $response = $this->actingAs($this->user)->get('/api/v1/reports/export?format=csv&preset=all_time');

        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertStringContainsString('Overview', $content);
        $this->assertStringContainsString('Financial position', $content);
        $this->assertStringContainsString('Total income', $content);
    }

    public function test_report_export_rejects_wallets_the_user_does_not_own(): void
    {
        $other = User::factory()->create();
        $otherWallet = $other->wallets()->create(['name' => 'Theirs', 'balance' => 0, 'currency' => 'USD']);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/reports/export?wallet_ids={$otherWallet->id}");

        $response->assertStatus(422);
        $this->assertContains($otherWallet->id, $response->json('errors.invalid_wallet_ids'));
    }

    public function test_export_route_does_not_shadow_a_transaction_lookup(): void
    {
        $transaction = $this->createTransaction();

        $response = $this->actingAs($this->user)->getJson("/api/v1/transactions/{$transaction['id']}");

        $response->assertStatus(200);
        $this->assertEquals($transaction['id'], $response->json('data.id'));
    }
}
