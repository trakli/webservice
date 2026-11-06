<?php

namespace Tests\Feature;

use App\Ai\Harnesses\TrakliHarness;
use App\Ai\Tools\Imports\ListImportRowsTool;
use App\Ai\Tools\Imports\RematchImportRowsTool;
use App\Ai\Tools\Imports\SaveImportRuleTool;
use App\Ai\Tools\Imports\UpdateImportRowsTool;
use App\Events\ImportSessionUpdated;
use App\Jobs\ProcessChatMessageJob;
use App\Models\AgentProposedAction;
use App\Models\ChatSession;
use App\Models\ImportSession;
use App\Models\User;
use App\Services\AgentRunner;
use App\Services\AiRouter;
use App\Services\AiService;
use App\Services\SuggestionEnricher;
use App\Types\TransactionSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use Whilesmart\Agents\Facades\Agents;
use Whilesmart\Agents\ValueObjects\AgentResult;
use Whilesmart\Agents\ValueObjects\ToolContext;

class ImportReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Storage::fake('local');
        config(['queue.default' => 'sync']);
        $this->mock(SuggestionEnricher::class, fn ($mock) => $mock->shouldReceive('enrich')->andReturnUsing(fn ($rows) => $rows));
    }

    private function analyze(): array
    {
        $xml = simplexml_load_file(base_path('tests/Fixtures/camt053.minimal.xml'));
        $entry = $xml->BkToCstmrStmt->Stmt->Ntry;
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['amount', 'currency', 'type', 'party', 'wallet', 'category', 'description', 'date']);
        $row = [(string) $entry->Amt, (string) $entry->Amt['Ccy'], 'income', (string) $entry->NtryDtls->TxDtls->RltdPties->Dbtr->Nm, '', '', (string) $entry->AddtlNtryInf, (string) $entry->BookgDt->Dt];
        fputcsv($stream, $row);
        fputcsv($stream, $row);
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        $id = $this->post('/api/v1/import/analyze', ['file' => UploadedFile::fake()->createWithContent('statement.csv', $csv)])
            ->assertOk()->json('data.id');

        return $this->getJson("/api/v1/import/sessions/{$id}")->assertOk()->assertJsonPath('data.status', 'ready')->json('data');
    }

    private function reviewPatch(array $session, array $changes, ?int $revision = null): array
    {
        return $this->patchJson("/api/v1/import/sessions/{$session['id']}/suggestions", ['revision' => $revision ?? $session['revision'], 'changes' => $changes])->assertOk()->json('data');
    }

    private function context(array $session, ?User $user = null): ToolContext
    {
        return ToolContext::forUser($user ?? $this->user, null, ['import_session_id' => $session['id']]);
    }

    public function test_analysis_ids_polling_and_legacy_backfill_are_stable(): void
    {
        Event::fake([ImportSessionUpdated::class]);
        $session = $this->analyze();
        $ids = array_column($session['suggestions'], 'id');
        $this->assertCount(2, array_unique($ids));
        $this->assertGreaterThan(0, $session['revision']);
        $this->getJson("/api/v1/import/sessions/{$session['id']}")->assertJsonPath('data.suggestions.0.id', $ids[0]);
        $this->getJson("/api/v1/import/sessions/{$session['id']}?since_revision={$session['revision']}")
            ->assertOk()->assertJsonPath('data', ['changed' => false, 'revision' => $session['revision']]);
        Event::assertDispatched(ImportSessionUpdated::class);
        $legacy = $session['suggestions'];
        unset($legacy[0]['id']);
        DB::table('import_sessions')->where('id', $session['id'])->update(['suggestions' => json_encode($legacy)]);
        $this->getJson('/api/v1/import/sessions')->assertOk();
        $stored = DB::table('import_sessions')->where('id', $session['id'])->first();
        $this->assertSame($legacy, json_decode($stored->suggestions, true));
        $this->assertSame($session['revision'], $stored->revision);
        $backfilled = $this->getJson("/api/v1/import/sessions/{$session['id']}")->assertOk()->json('data');
        $this->assertSame($ids[1], $backfilled['suggestions'][1]['id']);
        $this->assertSame($session['revision'] + 1, $backfilled['revision']);
        $this->assertEquals($backfilled['suggestions'], $this->getJson("/api/v1/import/sessions/{$session['id']}")->json('data.suggestions'));
    }

    public function test_failed_analysis_changes_revision_so_polling_sees_the_status(): void
    {
        Event::fake([ImportSessionUpdated::class]);
        $response = $this->post('/api/v1/import/analyze', ['file' => UploadedFile::fake()->createWithContent('empty.csv', "amount,currency,type,party,wallet,category,description,date\n")])->assertOk();
        $id = $response->json('data.id');
        $session = $this->getJson("/api/v1/import/sessions/{$id}?since_revision=0")->assertOk()->assertJsonPath('data.status', 'failed')->json('data');
        $this->assertGreaterThan(0, $session['revision']);
        Event::assertDispatched(ImportSessionUpdated::class, fn ($event) => $event->sessionId === $id && $event->revision === $session['revision']);
    }

    public function test_patch_groups_update_names_revision_and_user_metadata_with_stale_revision(): void
    {
        $session = $this->analyze();
        $party = $this->postJson('/api/v1/parties', ['name' => 'Amanda'])->assertCreated()->json('data');
        $wallet = $this->postJson('/api/v1/wallets', ['name' => 'Cash', 'currency' => 'EUR', 'type' => 'cash'])->assertCreated()->json('data');
        $category = $this->postJson('/api/v1/categories', ['name' => 'Groceries', 'type' => 'income'])->assertCreated()->json('data');
        Event::fake([ImportSessionUpdated::class]);
        $ids = array_column($session['suggestions'], 'id');
        $updated = $this->reviewPatch($session, [
            ['ids' => $ids, 'set' => ['party_id' => $party['id'], 'wallet_id' => $wallet['id'], 'category_id' => $category['id']]],
            ['ids' => [$ids[1]], 'set' => ['skip' => true]],
        ], 0);
        $this->assertSame($session['revision'] + 1, $updated['revision']);
        $this->assertSame('Amanda', $updated['suggestions'][0]['party']);
        $this->assertSame('Cash', $updated['suggestions'][0]['wallet']);
        $this->assertSame('Groceries', $updated['suggestions'][0]['category']);
        $this->assertSame('user', $updated['suggestions'][0]['edited_by']);
        $this->assertNotEmpty($updated['suggestions'][0]['edited_at']);
        Event::assertDispatched(ImportSessionUpdated::class, fn ($event) => $event->changedIds === $ids && $event->by === 'user' && $event->revision === $updated['revision']);
        $this->reviewPatch($session, [['ids' => [$ids[0]], 'set' => ['party_id' => null]]]);
        $this->getJson("/api/v1/import/sessions/{$session['id']}")->assertJsonPath('data.suggestions.0.party', null);
    }

    public function test_patch_rejects_foreign_targets_invalid_ids_and_nonready_sessions_atomically(): void
    {
        $session = $this->analyze();
        $other = User::factory()->create();
        $targets = [
            'party_id' => $other->parties()->create(['name' => 'Foreign'])->id,
            'category_id' => $other->categories()->create(['name' => 'Foreign', 'type' => 'income'])->id,
            'wallet_id' => $other->wallets()->create(['name' => 'Foreign', 'currency' => 'EUR', 'type' => 'cash'])->id,
        ];
        $url = "/api/v1/import/sessions/{$session['id']}/suggestions";
        foreach ($targets as $field => $id) {
            $this->patchJson($url, ['changes' => [
                ['ids' => [$session['suggestions'][0]['id']], 'set' => ['description' => 'Should roll back']],
                ['ids' => [$session['suggestions'][1]['id']], 'set' => [$field => $id]],
            ]])->assertUnprocessable();
        }
        $this->getJson("/api/v1/import/sessions/{$session['id']}")->assertJsonPath('data.revision', $session['revision']);
        $this->patchJson($url, ['changes' => [['ids' => ['missing'], 'set' => ['skip' => true]]]])->assertUnprocessable();
        ImportSession::findOrFail($session['id'])->update(['status' => 'confirmed']);
        $this->patchJson($url, ['changes' => [['ids' => [$session['suggestions'][0]['id']], 'set' => ['skip' => true]]]])->assertUnprocessable();
        $this->actingAs($other)->patchJson($url, ['changes' => []])->assertNotFound();
    }

    public function test_confirm_all_uses_stored_review_and_legacy_accepted_still_works(): void
    {
        $wallet = $this->postJson('/api/v1/wallets', ['name' => 'Cash', 'currency' => 'EUR', 'type' => 'cash'])->assertCreated()->json('data.id');
        $session = $this->analyze();
        $ids = array_column($session['suggestions'], 'id');
        $this->reviewPatch($session, [
            ['ids' => $ids, 'set' => ['wallet_id' => $wallet, 'amount' => 19, 'description' => 'Reviewed']],
            ['ids' => [$ids[1]], 'set' => ['skip' => true]],
        ]);
        $this->postJson('/api/v1/import/confirm', ['session_id' => $session['id'], 'accept_all_unskipped' => true])->assertOk()->assertJsonPath('data.created_count', 1);
        $this->assertDatabaseHas('transactions', ['wallet_id' => $wallet, 'amount' => 19, 'description' => 'Reviewed']);
        $this->assertSame(1, $this->user->transactions()->count());
        $session = $this->analyze();
        $this->postJson('/api/v1/import/confirm', ['session_id' => $session['id'], 'accepted' => [['index' => 0, 'wallet_id' => $wallet, 'description' => 'Legacy']]])->assertOk()->assertJsonPath('data.created_count', 1);
        $this->assertDatabaseHas('transactions', ['wallet_id' => $wallet, 'description' => 'Legacy']);
    }

    public function test_chat_links_owned_import_and_exposes_review_context(): void
    {
        $session = $this->analyze();
        Queue::fake();
        $chat = $this->postJson('/api/v1/ai/chats', ['message' => 'Review this import', 'import_session_id' => $session['id']])->assertAccepted()->json('data');
        $this->getJson("/api/v1/ai/chats/{$chat['id']}")->assertOk()->assertJsonPath('data.import_session_id', $session['id']);
        $prompt = app(TrakliHarness::class)->systemPrompt($this->context($session));
        $this->assertStringContainsString('statement.csv', $prompt);
        $this->assertStringContainsString('Never confirm the import', $prompt);
        $this->actingAs(User::factory()->create())->postJson('/api/v1/ai/chats', ['message' => 'Review', 'import_session_id' => $session['id']])->assertUnprocessable();
    }

    public function test_list_and_update_tools_share_state_resolve_names_and_fail_closed(): void
    {
        $session = $this->analyze();
        $context = $this->context($session);
        $party = $this->postJson('/api/v1/parties', ['name' => 'Amanda'])->assertCreated()->json('data');
        Event::fake([ImportSessionUpdated::class]);
        $tool = app(UpdateImportRowsTool::class);
        $result = $tool->handle(['match' => ['field' => 'counterparty', 'mode' => 'exact', 'pattern' => $session['suggestions'][0]['counterparty']], 'set' => ['party' => 'amanda']], $context);
        $this->assertSame(2, $result['changed']);
        $rows = app(ListImportRowsTool::class)->handle(['contains' => $session['suggestions'][0]['counterparty'], 'unassigned' => 'category', 'skipped' => false, 'ids' => [$session['suggestions'][0]['id']]], $context);
        $this->assertCount(1, $rows['rows']);
        $this->assertSame(2, $rows['totals']['row_count']);
        $this->assertSame('Amanda', $rows['rows'][0]['party']);
        $this->assertSame($party['id'], $rows['rows'][0]['party_id']);
        $this->assertSame('assistant', $rows['rows'][0]['edited_by']);
        Event::assertDispatched(ImportSessionUpdated::class, fn ($event) => $event->by === 'assistant');
        $error = $tool->handle(['ids' => [$session['suggestions'][0]['id']], 'set' => ['party' => 'Amand']], $context);
        $this->assertStringContainsString('Amanda', $error['error']);
        foreach ([ListImportRowsTool::class, UpdateImportRowsTool::class, SaveImportRuleTool::class, RematchImportRowsTool::class] as $class) {
            $result = app($class)->handle([], $this->context($session, User::factory()->create()));
            $this->assertSame('Import session not found.', $result['error']);
            $result = app($class)->handle(['session_id' => $session['id']], $this->context($session, User::factory()->create()));
            $this->assertSame('Import session not found.', $result['error']);
        }
        $other = User::factory()->create()->parties()->create(['name' => 'Foreign']);
        $error = $tool->handle(['ids' => [$session['suggestions'][0]['id']], 'set' => ['party_id' => $other->id]], $context);
        $this->assertArrayHasKey('error', $error);
    }

    public function test_save_rule_applies_immediately_and_rematch_preserves_user_edits(): void
    {
        $session = $this->analyze();
        $context = $this->context($session);
        Event::fake([ImportSessionUpdated::class]);
        $result = app(SaveImportRuleTool::class)->handle(['kind' => 'skip', 'match_field' => 'counterparty', 'match_mode' => 'contains', 'pattern' => $session['suggestions'][0]['counterparty']], $context);
        $this->assertSame(2, $result['changed']);
        $this->assertDatabaseHas('import_rules', ['id' => $result['rule_id'], 'source_session_id' => $session['id']]);
        $this->getJson("/api/v1/import/sessions/{$session['id']}")->assertJsonPath('data.suggestions.0.skip', true)->assertJsonPath('data.suggestions.0.edited_by', 'assistant');
        $this->reviewPatch($session, [['ids' => [$session['suggestions'][0]['id']], 'set' => ['description' => 'User choice']]]);
        $enricher = Mockery::mock(SuggestionEnricher::class);
        $enricher->shouldReceive('enrich')->once()->withArgs(function ($rows, $user, $documentContext, $notes, $instructions) {
            $this->assertCount(1, $rows);
            $this->assertContains('Keep the amount', $instructions);

            return true;
        })->andReturnUsing(fn ($rows) => [TransactionSuggestion::fromArray([...$rows[0]->toArray(), 'amount' => 29])]);
        $this->app->instance(SuggestionEnricher::class, $enricher);
        $result = app(RematchImportRowsTool::class)->handle(['instruction' => 'Keep the amount'], $context);
        $this->assertSame(1, $result['changed']);
        $this->getJson("/api/v1/import/sessions/{$session['id']}")->assertJsonPath('data.suggestions.0.description', 'User choice')->assertJsonPath('data.suggestions.0.edited_by', 'user')->assertJsonPath('data.suggestions.1.amount', 29)->assertJsonPath('data.suggestions.1.edited_by', 'assistant');
        Event::assertDispatched(ImportSessionUpdated::class, fn ($event) => $event->changedIds === [$session['suggestions'][1]['id']] && $event->by === 'assistant');
    }

    public function test_runner_receives_import_context_and_tools_can_use_an_explicit_session(): void
    {
        $session = $this->analyze();
        Queue::fake();
        $chat = $this->postJson('/api/v1/ai/chats', ['message' => 'Skip these rows', 'title' => 'Review', 'import_session_id' => $session['id']])->assertAccepted()->json('data');
        Agents::shouldReceive('stream')->once()->andReturnUsing(function ($harness, $input, $context) use ($session) {
            $this->assertSame($session['id'], $context->get('import_session_id'));
            $result = app(UpdateImportRowsTool::class)->handle(['ids' => array_column($session['suggestions'], 'id'), 'set' => ['skip' => true]], $context);
            $this->assertSame(2, $result['changed']);

            return AgentResult::success('Rows skipped.');
        });
        $message = ChatSession::findOrFail($chat['id'])->messages()->where('role', 'user')->firstOrFail();
        $assistant = $message->session->messages()->where('role', 'assistant')->firstOrFail();
        $router = Mockery::mock(AiRouter::class);
        $router->shouldNotReceive('classify');
        (new ProcessChatMessageJob($assistant))->handle(Mockery::mock(AiService::class), $router, app(AgentRunner::class));
        $this->assertSame('agent', $assistant->fresh()->result['source']);
        $result = app(ListImportRowsTool::class)->handle(['session_id' => $session['id']], ToolContext::forUser($this->user));
        $this->assertSame(2, $result['totals']['skipped']);
        $this->assertSame(0, AgentProposedAction::count());
    }

    public function test_rematch_leaves_unchanged_whole_number_amounts_unedited(): void
    {
        $session = $this->analyze();
        $this->reviewPatch($session, [['ids' => array_column($session['suggestions'], 'id'), 'set' => ['amount' => 20]]]);
        $session = $this->getJson("/api/v1/import/sessions/{$session['id']}")->json('data');
        $rows = $session['suggestions'];
        foreach ($rows as &$row) {
            unset($row['edited_by'], $row['edited_at']);
        }
        unset($row);
        ImportSession::findOrFail($session['id'])->update(['suggestions' => $rows]);
        $before = $this->getJson("/api/v1/import/sessions/{$session['id']}")->json('data');
        $result = app(RematchImportRowsTool::class)->handle(['instruction' => 'Keep the statement values'], $this->context($session));
        $this->assertSame(0, $result['changed']);
        $this->assertSame($before['revision'], $result['revision']);
    }

    public function test_rematch_does_not_overwrite_a_user_edit_made_during_enrichment(): void
    {
        $session = $this->analyze();
        $enricher = Mockery::mock(SuggestionEnricher::class);
        $enricher->shouldReceive('enrich')->once()->andReturnUsing(function ($rows) use ($session) {
            $this->reviewPatch($session, [['ids' => [$session['suggestions'][0]['id']], 'set' => ['amount' => 41]]]);

            return array_map(fn ($row) => TransactionSuggestion::fromArray([...$row->toArray(), 'amount' => 29]), $rows);
        });
        $this->app->instance(SuggestionEnricher::class, $enricher);
        Event::fake([ImportSessionUpdated::class]);
        $result = app(RematchImportRowsTool::class)->handle(['instruction' => 'Review the amounts'], $this->context($session));
        $this->assertSame(1, $result['changed']);
        $this->getJson("/api/v1/import/sessions/{$session['id']}")->assertJsonPath('data.suggestions.0.amount', 41)->assertJsonPath('data.suggestions.0.edited_by', 'user');
        Event::assertDispatched(ImportSessionUpdated::class, fn ($event) => $event->by === 'assistant' && $event->changedIds === [$session['suggestions'][1]['id']]);
    }

    public function test_broadcast_failures_and_log_driver_do_not_break_review_edits(): void
    {
        $session = $this->analyze();
        config(['broadcasting.default' => 'log']);
        $this->reviewPatch($session, [['ids' => [$session['suggestions'][0]['id']], 'set' => ['skip' => true]]]);
        Broadcast::shouldReceive('queue')->andThrow(new \RuntimeException('Broadcaster unavailable'));
        $updated = $this->reviewPatch($session, [['ids' => [$session['suggestions'][0]['id']], 'set' => ['skip' => false]]]);
        $this->assertFalse($updated['suggestions'][0]['skip']);
    }

    public function test_import_channel_authorization_and_event_contract(): void
    {
        $session = $this->analyze();
        config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher.key' => 'your-app-key-here', 'broadcasting.connections.pusher.secret' => 'your-app-secret-here', 'broadcasting.connections.pusher.app_id' => 'test-app']);
        require base_path('routes/channels.php');
        $payload = ['socket_id' => '123.456', 'channel_name' => 'private-import-session.'.$session['id']];
        $this->postJson('/broadcasting/auth', $payload)->assertOk()->assertJsonStructure(['auth']);
        $this->actingAs(User::factory()->create())->postJson('/broadcasting/auth', $payload)->assertForbidden();
        $event = new ImportSessionUpdated($session['id'], $session['revision'], [$session['suggestions'][0]['id']], 'assistant');
        $this->assertSame('updated', $event->broadcastAs());
        $this->assertSame('private-import-session.'.$session['id'], $event->broadcastOn()[0]->name);
        $this->assertSame(['revision' => $session['revision'], 'changed_ids' => [$session['suggestions'][0]['id']], 'by' => 'assistant'], $event->broadcastWith());
    }
}
