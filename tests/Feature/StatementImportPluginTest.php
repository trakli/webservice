<?php

namespace Tests\Feature;

use App\Contracts\IntegrationUi;
use App\Enums\TransactionType;
use App\Models\User;
use App\Services\DocumentProcessorManager;
use App\Services\IntegrationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use Trakli\StatementImport\StatementImportServiceProvider;

class StatementImportPluginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The statement-import plugin is an embedded plugin, not part of the
        // core source tree, so skip when it is not installed.
        if (! class_exists(StatementImportServiceProvider::class)) {
            $this->markTestSkipped('statement-import plugin is not installed.');
        }

        // The plugin ships disabled; boot it explicitly so the test exercises
        // the same registration its service provider performs when enabled.
        (new StatementImportServiceProvider($this->app))->boot();
    }

    public function test_it_registers_a_ui_integration(): void
    {
        $integration = app(IntegrationRegistry::class)->get('statement-import');

        $this->assertInstanceOf(IntegrationUi::class, $integration);
        $this->assertContains('settings.integrations', $integration->ui()['slots']);
        $this->assertContains('onboarding.steps', $integration->ui()['slots']);
    }

    public function test_it_turns_a_camt_statement_into_transaction_suggestions(): void
    {
        $manager = app(DocumentProcessorManager::class);

        $processor = $manager->getProcessor('application/xml', 'xml');

        $file = new UploadedFile(
            base_path('tests/Fixtures/camt053.minimal.xml'),
            'statement.xml',
            'application/xml',
            null,
            true
        );

        $suggestions = $processor->process($file, User::factory()->create());

        $this->assertCount(1, $suggestions);

        $suggestion = $suggestions[0];
        $this->assertSame(8.85, $suggestion->amount);
        $this->assertSame('EUR', $suggestion->currency);
        $this->assertSame(TransactionType::INCOME->value, $suggestion->type);
        $this->assertSame('2014-12-31', $suggestion->date);
        $this->assertSame('camt', $suggestion->documentType);
    }
}
