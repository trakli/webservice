<?php

namespace Tests\Feature;

use App\Ai\BlockCollector;
use App\Ai\Tools\Documents\ImportDocumentTool;
use App\Jobs\AnalyzeImportJob;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\DocumentProcessorManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Whilesmart\Agents\ValueObjects\ToolContext;

class AgentDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected ChatSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->session = ChatSession::create([
            'owner_type' => $this->user->getMorphClass(),
            'owner_id' => $this->user->id,
        ]);
    }

    private function message(): ChatMessage
    {
        return $this->session->messages()->create([
            'user_id' => $this->user->id,
            'role' => ChatMessage::ROLE_USER,
            'content' => 'import this',
        ]);
    }

    public function test_upload_attaches_files_to_a_chat_message(): void
    {
        Storage::fake();
        $message = $this->message();

        $this->actingAs($this->user)
            ->postJson("/api/v1/ai/chats/{$this->session->id}/messages/{$message->id}/files", [
                'files' => [UploadedFile::fake()->create('statement.csv', 12, 'text/csv')],
            ])
            ->assertStatus(200);

        $this->assertSame(1, $message->fresh()->files()->count());
    }

    public function test_upload_persists_the_document_type(): void
    {
        Storage::fake();
        $message = $this->message();

        $this->actingAs($this->user)
            ->postJson("/api/v1/ai/chats/{$this->session->id}/messages/{$message->id}/files", [
                'files' => [UploadedFile::fake()->create('statement.csv', 12, 'text/csv')],
                'document_type' => 'bank_statement',
            ])
            ->assertStatus(200);

        $this->assertEquals('bank_statement', $message->fresh()->files()->first()->metadata['document_type']);
    }

    public function test_upload_rejects_message_from_another_session(): void
    {
        Storage::fake();
        $otherSession = ChatSession::create([
            'owner_type' => $this->user->getMorphClass(),
            'owner_id' => $this->user->id,
        ]);
        $message = $this->message();

        $this->actingAs($this->user)
            ->postJson("/api/v1/ai/chats/{$otherSession->id}/messages/{$message->id}/files", [
                'files' => [UploadedFile::fake()->create('x.csv', 1, 'text/csv')],
            ])
            ->assertStatus(404);
    }

    public function test_import_document_errors_without_an_attachment(): void
    {
        $this->app->instance(BlockCollector::class, new BlockCollector());
        $tool = $this->app->make(ImportDocumentTool::class);
        $context = ToolContext::forUser($this->user, 'en', ['chat_session_id' => $this->session->id]);

        $out = $tool->handle([], $context);

        $this->assertArrayHasKey('error', $out);
    }

    public function test_import_document_starts_analysis_and_renders_review(): void
    {
        Storage::fake();
        Queue::fake();

        Storage::put('chat_attachments/statement.csv', "date,amount\n2026-06-01,10");
        $message = $this->message();
        $message->files()->create(['path' => 'chat_attachments/statement.csv', 'type' => 'document']);

        // Make analysis deterministic: the external processor is mocked.
        $processors = $this->mock(DocumentProcessorManager::class);
        $processors->shouldReceive('canHandle')->andReturn(true);
        $processors->shouldReceive('getProcessor')->andReturn(\Mockery::mock(\App\Contracts\DocumentProcessor::class));

        $collector = new BlockCollector();
        $this->app->instance(BlockCollector::class, $collector);
        $tool = $this->app->make(ImportDocumentTool::class);
        $context = ToolContext::forUser($this->user, 'en', ['chat_session_id' => $this->session->id]);

        $msg = $tool->handle(['document_type' => 'bank_statement'], $context);

        $this->assertStringContainsString('import session', $msg);
        $this->assertSame(1, $this->user->importSessions()->count());
        Queue::assertPushed(AnalyzeImportJob::class);

        $block = $collector->all()[0];
        $this->assertSame('import_review', $block['type']);
        $this->assertSame('analyzing', $block['status']);
    }
}
