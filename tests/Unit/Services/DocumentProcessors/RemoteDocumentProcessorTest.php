<?php

namespace Tests\Unit\Services\DocumentProcessors;

use App\Models\User;
use App\Services\DocumentProcessors\RemoteDocumentProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

class RemoteDocumentProcessorTest extends TestCase
{
    use RefreshDatabase;

    private RemoteDocumentProcessor $processor;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new RemoteDocumentProcessor();
        $this->user = User::factory()->create();
    }

    public function test_supports_returns_false_when_no_url_configured(): void
    {
        config(['services.document_processor.url' => null]);

        $this->assertFalse($this->processor->supports('application/pdf', 'pdf'));
        $this->assertFalse($this->processor->supports('image/png', 'png'));
    }

    public function test_supports_returns_true_for_pdf_when_url_configured(): void
    {
        config(['services.document_processor.url' => 'https://example.com/parse']);

        $this->assertTrue($this->processor->supports('application/pdf', 'pdf'));
    }

    public function test_supports_returns_true_for_image_when_url_configured(): void
    {
        config(['services.document_processor.url' => 'https://example.com/parse']);

        $this->assertTrue($this->processor->supports('image/png', 'png'));
        $this->assertTrue($this->processor->supports('image/jpeg', 'jpg'));
        $this->assertTrue($this->processor->supports('image/jpeg', 'jpeg'));
        $this->assertTrue($this->processor->supports('image/tiff', 'tiff'));
    }

    public function test_supports_returns_false_for_unsupported_type_even_with_url(): void
    {
        config(['services.document_processor.url' => 'https://example.com/parse']);

        $this->assertFalse($this->processor->supports('text/plain', 'txt'));
        $this->assertFalse($this->processor->supports('text/csv', 'csv'));
    }

    public function test_process_returns_empty_when_no_url_configured(): void
    {
        config(['services.document_processor.url' => null]);

        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $result = $this->processor->process($file, $this->user);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_parse_response_in_fields_mode(): void
    {
        config([
            'services.document_processor.url' => 'https://example.com/parse',
            'services.document_processor.auth_type' => 'apikey',
            'services.document_processor.auth_credentials' => 'test-key-123',
            'services.document_processor.auth_header' => 'X-API-Key',
            'services.document_processor.response_mapping' => [
                'mode' => 'fields',
                'transactions_path' => 'transactions',
                'date_field' => 'date',
                'description_field' => 'description',
                'amount_field' => 'amount',
                'currency_field' => 'currency',
            ],
        ]);

        Http::fake([
            'https://example.com/parse' => Http::response([
                'transactions' => [
                    [
                        'date' => '2025-01-15',
                        'description' => 'Coffee Shop',
                        'amount' => -5.50,
                        'currency' => 'USD',
                    ],
                    [
                        'date' => '2025-01-16',
                        'description' => 'Salary deposit',
                        'amount' => 3000.00,
                        'currency' => 'USD',
                    ],
                ],
            ], 200),
        ]);

        $file = UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf');

        $result = $this->processor->process($file, $this->user);

        $this->assertCount(2, $result);

        $this->assertEquals(5.50, $result[0]->amount);
        $this->assertEquals('expense', $result[0]->type);
        $this->assertEquals('USD', $result[0]->currency);
        $this->assertEquals('Coffee Shop', $result[0]->description);
        $this->assertEquals('2025-01-15', $result[0]->date);
        $this->assertEquals('remote', $result[0]->documentType);

        $this->assertEquals(3000.00, $result[1]->amount);
        $this->assertEquals('income', $result[1]->type);
    }

    public function test_parse_response_in_text_block_mode(): void
    {
        config([
            'services.document_processor.url' => 'https://example.com/parse',
            'services.document_processor.auth_type' => 'none',
            'services.document_processor.response_mapping' => [
                'mode' => 'text_block',
                'transactions_path' => 'elements',
                'content_field' => 'content',
                'filter' => ['key' => 'subtype', 'value' => 'paragraph'],
                'line_mapping' => [
                    'date' => 0,
                    'description' => 1,
                    'amount' => 2,
                    'currency' => 3,
                ],
            ],
        ]);

        Http::fake([
            'https://example.com/parse' => Http::response([
                'elements' => [
                    [
                        'subtype' => 'paragraph',
                        'content' => "2025-03-15\nCard charge (Netflix)\n-15.99\nUSD",
                    ],
                    [
                        'subtype' => 'header',
                        'content' => 'Bank Statement',
                    ],
                    [
                        'subtype' => 'paragraph',
                        'content' => "2025-03-16\nDirect deposit\n2500.00\nUSD",
                    ],
                ],
            ], 200),
        ]);

        $file = UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf');

        $result = $this->processor->process($file, $this->user);

        $this->assertCount(2, $result);

        $this->assertEquals(15.99, $result[0]->amount);
        $this->assertEquals('expense', $result[0]->type);
        $this->assertEquals('Netflix', $result[0]->party);
        $this->assertEquals('Card charge (Netflix)', $result[0]->description);
        $this->assertEquals('2025-03-15', $result[0]->date);

        $this->assertEquals(2500.00, $result[1]->amount);
        $this->assertEquals('income', $result[1]->type);
    }

    public function test_normalize_date_strips_commas(): void
    {
        config([
            'services.document_processor.url' => 'https://example.com/parse',
            'services.document_processor.auth_type' => 'none',
            'services.document_processor.response_mapping' => [
                'mode' => 'fields',
                'transactions_path' => 'transactions',
                'date_field' => 'date',
                'amount_field' => 'amount',
            ],
        ]);

        Http::fake([
            'https://example.com/parse' => Http::response([
                'transactions' => [
                    [
                        'date' => '05 Apr, 2024',
                        'amount' => 100.00,
                    ],
                ],
            ], 200),
        ]);

        $file = UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf');

        $result = $this->processor->process($file, $this->user);

        $this->assertCount(1, $result);
        $this->assertEquals('2024-04-05', $result[0]->date);
    }

    public function test_graceful_handling_when_remote_returns_error(): void
    {
        config([
            'services.document_processor.url' => 'https://example.com/parse',
            'services.document_processor.auth_type' => 'none',
        ]);

        Http::fake([
            'https://example.com/parse' => Http::response('Internal Server Error', 500),
        ]);

        $file = UploadedFile::fake()->create('bad.pdf', 100, 'application/pdf');

        $result = $this->processor->process($file, $this->user);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_auth_headers_sent_for_apikey_type(): void
    {
        config([
            'services.document_processor.url' => 'https://example.com/parse',
            'services.document_processor.auth_type' => 'apikey',
            'services.document_processor.auth_credentials' => 'my-secret-key',
            'services.document_processor.auth_header' => 'X-API-Key',
            'services.document_processor.response_mapping' => [
                'mode' => 'fields',
                'transactions_path' => 'transactions',
            ],
        ]);

        Http::fake([
            'https://example.com/parse' => Http::response([
                'transactions' => [],
            ], 200),
        ]);

        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $this->processor->process($file, $this->user);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-API-Key', 'my-secret-key');
        });
    }

    public function test_bearer_auth_type(): void
    {
        config([
            'services.document_processor.url' => 'https://example.com/parse',
            'services.document_processor.auth_type' => 'bearer',
            'services.document_processor.auth_credentials' => 'my-bearer-token',
            'services.document_processor.response_mapping' => [
                'mode' => 'fields',
                'transactions_path' => 'transactions',
            ],
        ]);

        Http::fake([
            'https://example.com/parse' => Http::response([
                'transactions' => [],
            ], 200),
        ]);

        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $this->processor->process($file, $this->user);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer my-bearer-token');
        });
    }

    public function test_skipped_status_transactions_excluded_in_text_block_mode(): void
    {
        config([
            'services.document_processor.url' => 'https://example.com/parse',
            'services.document_processor.auth_type' => 'none',
            'services.document_processor.response_mapping' => [
                'mode' => 'text_block',
                'transactions_path' => 'elements',
                'content_field' => 'content',
                'line_mapping' => [
                    'date' => 0,
                    'description' => 1,
                    'amount' => 2,
                    'currency' => 3,
                    'status' => 4,
                ],
            ],
        ]);

        Http::fake([
            'https://example.com/parse' => Http::response([
                'elements' => [
                    [
                        'content' => "2025-01-10\nPayment\n-50.00\nUSD\ncanceled",
                    ],
                    [
                        'content' => "2025-01-11\nDeposit\n100.00\nUSD\ncompleted",
                    ],
                ],
            ], 200),
        ]);

        $file = UploadedFile::fake()->create('stmt.pdf', 100, 'application/pdf');

        $result = $this->processor->process($file, $this->user);

        $this->assertCount(1, $result);
        $this->assertEquals(100.00, $result[0]->amount);
    }

    public function test_llm_fallback_when_mapping_returns_empty(): void
    {
        config([
            'services.document_processor.url' => 'https://example.com/parse',
            'services.document_processor.auth_type' => 'none',
            'services.document_processor.response_mapping' => [
                'mode' => 'fields',
                'transactions_path' => 'transactions',
            ],
        ]);

        Http::fake([
            'https://example.com/parse' => Http::response([
                'transactions' => [],
                'elements' => [
                    ['content' => 'Jan 15, 2025 - Coffee purchase $5.50'],
                ],
            ], 200),
        ]);

        $llmResponse = json_encode([
            [
                'date' => '2025-01-15',
                'description' => 'Coffee purchase',
                'amount' => -5.50,
                'currency' => 'USD',
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

        $file = UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf');

        $result = $this->processor->process($file, $this->user);

        $this->assertCount(1, $result);
        $this->assertEquals(5.50, $result[0]->amount);
        $this->assertEquals('expense', $result[0]->type);
        $this->assertEquals('llm_fallback', $result[0]->documentType);
    }
}
