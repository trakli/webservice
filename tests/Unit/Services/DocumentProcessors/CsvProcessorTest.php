<?php

namespace Tests\Unit\Services\DocumentProcessors;

use App\Models\User;
use App\Services\DocumentProcessors\CsvProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CsvProcessorTest extends TestCase
{
    use RefreshDatabase;

    private CsvProcessor $processor;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = app(CsvProcessor::class);
        $this->user = User::factory()->create();
    }

    public function test_processing_valid_csv_with_transactions(): void
    {
        $csv = "amount,currency,type,party,wallet,category,description,date\n"
            . "100,USD,expense,John Doe,Checking,Food,Lunch,2025-01-01\n"
            . "200,EUR,income,Employer,Savings,Salary,Monthly pay,2025-01-02\n";

        $file = UploadedFile::fake()->createWithContent('test.csv', $csv);

        $suggestions = $this->processor->process($file, $this->user);

        $this->assertCount(2, $suggestions);

        $this->assertEquals(100.0, $suggestions[0]->amount);
        $this->assertEquals('USD', $suggestions[0]->currency);
        $this->assertEquals('expense', $suggestions[0]->type);
        $this->assertEquals('John Doe', $suggestions[0]->party);
        $this->assertEquals('Checking', $suggestions[0]->wallet);
        $this->assertEquals('Food', $suggestions[0]->category);
        $this->assertEquals('Lunch', $suggestions[0]->description);
        $this->assertEquals('2025-01-01', $suggestions[0]->date);
        $this->assertEquals(1.0, $suggestions[0]->confidence);
        $this->assertEquals('csv', $suggestions[0]->documentType);

        $this->assertEquals(200.0, $suggestions[1]->amount);
        $this->assertEquals('EUR', $suggestions[1]->currency);
        $this->assertEquals('income', $suggestions[1]->type);
        $this->assertEquals('Employer', $suggestions[1]->party);
    }

    public function test_skips_header_row(): void
    {
        $csv = "amount,currency,type,party,wallet,category,description,date\n"
            . "50,USD,expense,Store,Wallet,Food,Snacks,2025-03-01\n";

        $file = UploadedFile::fake()->createWithContent('test.csv', $csv);

        $suggestions = $this->processor->process($file, $this->user);

        $this->assertCount(1, $suggestions);
        $this->assertEquals(50.0, $suggestions[0]->amount);
    }

    public function test_invalid_dates_get_low_confidence(): void
    {
        $csv = "amount,currency,type,party,wallet,category,description,date\n"
            . "100,USD,expense,Store,Wallet,Food,Lunch,01/15/2025\n";

        $file = UploadedFile::fake()->createWithContent('test.csv', $csv);

        $suggestions = $this->processor->process($file, $this->user);

        $this->assertCount(1, $suggestions);
        $this->assertEquals(0.3, $suggestions[0]->confidence);
        $this->assertEquals('01/15/2025', $suggestions[0]->date);
    }

    public function test_valid_dates_get_full_confidence(): void
    {
        $csv = "amount,currency,type,party,wallet,category,description,date\n"
            . "100,USD,expense,Store,Wallet,Food,Lunch,2025-01-15\n";

        $file = UploadedFile::fake()->createWithContent('test.csv', $csv);

        $suggestions = $this->processor->process($file, $this->user);

        $this->assertCount(1, $suggestions);
        $this->assertEquals(1.0, $suggestions[0]->confidence);
    }

    public function test_empty_file_returns_empty(): void
    {
        $csv = "amount,currency,type,party,wallet,category,description,date\n";

        $file = UploadedFile::fake()->createWithContent('empty.csv', $csv);

        $suggestions = $this->processor->process($file, $this->user);

        $this->assertCount(0, $suggestions);
    }

    public function test_supports_csv_mime_type(): void
    {
        $this->assertTrue($this->processor->supports('text/csv', 'csv'));
    }

    public function test_supports_csv_extension(): void
    {
        $this->assertTrue($this->processor->supports('application/octet-stream', 'csv'));
    }

    public function test_does_not_support_non_csv(): void
    {
        $this->assertFalse($this->processor->supports('application/pdf', 'pdf'));
        $this->assertFalse($this->processor->supports('text/plain', 'txt'));
        $this->assertFalse($this->processor->supports('image/png', 'png'));
    }

    public function test_invalid_transaction_type_sets_type_to_null(): void
    {
        $csv = "amount,currency,type,party,wallet,category,description,date\n"
            . "100,USD,+Transfer,John,Wallet,Cat,Desc,2025-01-01\n";

        $file = UploadedFile::fake()->createWithContent('test.csv', $csv);

        $suggestions = $this->processor->process($file, $this->user);

        $this->assertCount(1, $suggestions);
        $this->assertNull($suggestions[0]->type);
    }

    public function test_case_insensitive_transaction_type(): void
    {
        $csv = "amount,currency,type,party,wallet,category,description,date\n"
            . "100,USD,EXPENSE,Store,Wallet,Food,Lunch,2025-01-01\n"
            . "200,USD,Income,Employer,Wallet,Salary,Pay,2025-01-02\n";

        $file = UploadedFile::fake()->createWithContent('test.csv', $csv);

        $suggestions = $this->processor->process($file, $this->user);

        $this->assertCount(2, $suggestions);
        $this->assertEquals('expense', $suggestions[0]->type);
        $this->assertEquals('income', $suggestions[1]->type);
    }
}
