<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DocumentProcessorManager;
use App\Services\DocumentProcessors\RemoteDocumentProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

class ImportRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Storage::fake('local');
        config(['queue.default' => 'sync']);
    }

    private function alias(array $overrides = []): array
    {
        $party = $this->user->parties()->create(['name' => 'Amanda']);

        return array_replace([
            'kind' => 'party_alias', 'match_field' => 'counterparty', 'match_mode' => 'contains',
            'pattern' => 'AMANDA KEMAJOU', 'target_type' => 'party', 'target_id' => $party->id,
        ], $overrides);
    }

    private function createRule(array $rule): array
    {
        return $this->actingAs($this->user)->postJson('/api/v1/import-rules', $rule)
            ->assertCreated()->json('data');
    }

    public function test_crud_uses_flat_pagination_and_isolates_owners(): void
    {
        $rule = $this->createRule($this->alias());
        $this->getJson('/api/v1/import-rules')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $rule['id']);
        $this->patchJson('/api/v1/import-rules/' . $rule['id'], ['active' => false])
            ->assertOk()->assertJsonPath('data.active', false);
        $this->getJson('/api/v1/import-rules/' . $rule['id'])->assertOk();

        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/api/v1/import-rules')->assertJsonPath('data.total', 0);
        $this->getJson('/api/v1/import-rules/' . $rule['id'])->assertNotFound();
        $this->patchJson('/api/v1/import-rules/' . $rule['id'], ['active' => true])->assertNotFound();
        $this->deleteJson('/api/v1/import-rules/' . $rule['id'])->assertNotFound();
        $this->actingAs($this->user)->deleteJson('/api/v1/import-rules/' . $rule['id'])->assertOk();
        $this->assertSoftDeleted('import_rules', ['id' => $rule['id']]);
        $this->getJson('/api/v1/import-rules')->assertJsonPath('data.total', 0);
    }

    public function test_rule_combinations_and_target_ownership_are_validated(): void
    {
        $alias = $this->alias();
        $foreign = User::factory()->create()->parties()->create(['name' => 'Other']);
        foreach ([
            ['target_id' => $foreign->id], ['target_type' => 'wallet'], ['target_id' => null],
            ['match_field' => null], ['match_mode' => null], ['pattern' => '   '],
            ['kind' => 'skip'], ['kind' => 'instruction', 'instruction' => 'Remember'],
            ['kind' => 'invalid'], ['instruction' => 'Not allowed'],
        ] as $invalid) {
            $this->actingAs($this->user)->postJson('/api/v1/import-rules', array_replace($alias, $invalid))->assertUnprocessable();
        }
        $this->createRule(['kind' => 'instruction', 'instruction' => 'Use the existing parties only.']);
        $this->postJson('/api/v1/import-rules', ['kind' => 'instruction', 'instruction' => str_repeat('x', 501)])->assertUnprocessable();
        $this->postJson('/api/v1/import-rules', ['kind' => 'instruction'])->assertUnprocessable();
        $category = $this->user->categories()->create(['name' => 'Household', 'type' => 'expense']);
        $wallet = $this->user->wallets()->create(['name' => 'MoMo', 'type' => 'mobile', 'currency' => 'XAF']);
        $this->createRule(array_replace($alias, ['kind' => 'category_hint', 'target_type' => 'category', 'target_id' => $category->id]));
        $this->createRule(array_replace($alias, ['kind' => 'wallet_hint', 'target_type' => 'wallet', 'target_id' => $wallet->id]));
    }

    private function response(array $data): TextResponse
    {
        return new TextResponse(
            steps: collect([]),
            text: json_encode($data),
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(0, 0),
            meta: new Meta('fake', 'fake'),
            messages: collect([]),
        );
    }

    private function analyze(array $enrichment = [], ?string $notes = null, bool $multiple = false): array
    {
        config(['services.document_processor.url' => 'https://extractor.test/parse', 'services.document_processor.response_mapping.mode' => 'text_block']);
        $manager = new DocumentProcessorManager();
        $manager->register(new RemoteDocumentProcessor());
        $this->app->instance(DocumentProcessorManager::class, $manager);
        Http::fake(['extractor.test/*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/momo-statement.sample.json')), true))]);
        $transactions = [[
                'amount' => 2000, 'currency' => 'XAF', 'direction' => 'expense',
                'counterparty_name' => 'AMANDA KEMAJOU SAMPLE', 'description' => 'MOMO USER Charges',
                'date' => '2026-06-15', 'fee' => 8, 'reference' => '00000000001',
        ]];
        if ($multiple) {
            $transactions[] = array_replace($transactions[0], ['amount' => 200000, 'fee' => 404, 'reference' => '00000000002']);
        }
        $fake = Prism::fake([
            $this->response($transactions),
            $this->response($enrichment ?: ['transactions' => [['party' => 'LLM override']], 'proposed_rules' => []]),
        ]);
        $response = $this->actingAs($this->user)->post('/api/v1/import/analyze', [
            'file' => UploadedFile::fake()->create('statement.pdf', 1, 'application/pdf'),
            'document_type' => 'bank_statement', 'notes' => $notes,
        ])->assertOk();
        $session = $this->getJson('/api/v1/import/sessions/' . $response->json('data.id'))->assertOk()->json('data');
        $this->assertSame('ready', $session['status']);

        return [$session, $fake];
    }

    public function test_notes_and_document_type_reach_both_prompts(): void
    {
        $this->createRule(['kind' => 'instruction', 'instruction' => 'Keep the payment reference.']);
        [$session, $fake] = $this->analyze(notes: 'AMANDA KEMAJOU is Amanda.');
        $this->assertSame('AMANDA KEMAJOU is Amanda.', $session['notes']);
        $fake->assertRequest(function ($requests) {
            $this->assertCount(2, $requests);
            foreach ($requests as $request) {
                $this->assertStringContainsString('USER INSTRUCTIONS (from the account owner;', $request->prompt());
                $this->assertStringContainsString('AMANDA KEMAJOU is Amanda.', $request->prompt());
                $this->assertStringContainsString('bank_statement', $request->prompt());
            }
            $this->assertStringContainsString('Keep the payment reference.', $requests[1]->prompt());
        });
    }

    public function test_saved_rules_pin_entities_before_enrichment_and_skip_rows(): void
    {
        $alias = $this->alias(['pattern' => '  amanda   kemajou  ']);
        $rule = $this->createRule($alias);
        $laterParty = $this->user->parties()->create(['name' => 'Later alias']);
        $later = $this->createRule(array_replace($alias, ['target_id' => $laterParty->id]));
        $this->createRule(['kind' => 'skip', 'match_field' => 'description', 'match_mode' => 'starts_with', 'pattern' => 'momo user']);
        $category = $this->user->categories()->create(['name' => 'Household', 'type' => 'expense']);
        $wallet = $this->user->wallets()->create(['name' => 'MoMo', 'currency' => 'XAF', 'type' => 'mobile']);
        $this->createRule(array_replace($alias, ['kind' => 'category_hint', 'target_type' => 'category', 'target_id' => $category->id]));
        $this->createRule(array_replace($alias, ['kind' => 'wallet_hint', 'target_type' => 'wallet', 'target_id' => $wallet->id]));
        [$session, $fake] = $this->analyze(['transactions' => [['party' => 'Wrong', 'category' => 'Wrong', 'wallet' => 'Wrong']]]);
        $suggestion = $session['suggestions'][0];
        $this->assertSame($alias['target_id'], $suggestion['party_id']);
        $this->assertSame('Amanda', $suggestion['party']);
        $this->assertSame($category->id, $suggestion['category_id']);
        $this->assertSame('Household', $suggestion['category']);
        $this->assertSame($wallet->id, $suggestion['wallet_id']);
        $this->assertSame('MoMo', $suggestion['wallet']);
        $this->assertTrue($suggestion['skip']);
        $this->assertCount(4, $suggestion['matched_rules']);
        $this->assertSame(8.0, (float) $suggestion['fee']);
        $used = $this->getJson('/api/v1/import-rules/' . $rule['id'])->assertJsonPath('data.hits', 1);
        $this->assertNotNull($used->json('data.last_used_at'));
        $this->assertDatabaseHas('import_rules', ['id' => $rule['id'], 'hits' => 1]);
        $this->assertDatabaseHas('import_rules', ['id' => $later['id'], 'hits' => 0]);
        $fake->assertRequest(function ($requests) {
            $this->assertStringContainsString('"party":"Amanda"', $requests[1]->prompt());
            $this->assertStringContainsString('"party":true', $requests[1]->prompt());
        });
    }

    public function test_multiple_matches_increment_each_rule_once_per_import(): void
    {
        $rule = $this->createRule($this->alias());
        $skip = $this->createRule(['kind' => 'skip', 'match_field' => 'description', 'match_mode' => 'starts_with', 'pattern' => 'momo user']);
        $updates = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$updates) {
            if (preg_match('/^update [`"]?import_rules[`"]? /i', $query->sql)) {
                $updates[] = $query;
            }
        });
        [$session] = $this->analyze(multiple: true);
        $this->assertCount(2, $session['suggestions']);
        $this->assertCount(2, $updates);
        foreach ([$rule, $skip] as $matched) {
            $response = $this->getJson('/api/v1/import-rules/' . $matched['id'])
                ->assertOk()->assertJsonPath('data.hits', 2);
            $this->assertNotNull($response->json('data.last_used_at'));
        }
    }

    public function test_proposals_resolve_owned_targets_and_drop_unknown_or_unmatched_patterns(): void
    {
        $alias = $this->alias();
        $proposal = array_diff_key($alias, array_flip(['target_type', 'target_id']));
        $proposal['target_name'] = 'amanda';
        [$session] = $this->analyze([
            'transactions' => [['party' => 'Amanda', 'note_applied' => true]],
            'proposed_rules' => [$proposal, array_replace($proposal, ['target_name' => 'Missing']), array_replace($proposal, ['pattern' => 'Absent'])],
        ], 'AMANDA KEMAJOU is Amanda.');
        $this->assertCount(1, $session['metadata']['proposed_rules']);
        $this->assertSame($alias['target_id'], $session['metadata']['proposed_rules'][0]['target_id']);
        $this->assertSame('Amanda', $session['metadata']['proposed_rules'][0]['target_name']);
        $this->assertSame(1, $session['metadata']['proposed_rules'][0]['matches']);
        $this->assertTrue($session['suggestions'][0]['note_applied']);
    }

    public function test_notes_can_reject_rows_without_removing_them(): void
    {
        [$session] = $this->analyze(['transactions' => [['skip' => true, 'note_applied' => true]]], 'Ignore MOMO USER charges.');
        $this->assertCount(1, $session['suggestions']);
        $this->assertTrue($session['suggestions'][0]['skip']);
        $this->assertTrue($session['suggestions'][0]['note_applied']);
    }

    public function test_confirm_saves_rules_for_the_next_import_and_rejects_foreign_targets_first(): void
    {
        [$session] = $this->analyze();
        $alias = $this->alias();
        $wallet = $this->user->wallets()->create(['name' => 'MoMo', 'type' => 'mobile', 'currency' => 'XAF']);
        $payload = ['session_id' => $session['id'], 'accepted' => [['index' => 0, 'wallet_id' => $wallet->id]], 'save_rules' => [$alias]];
        $foreign = User::factory()->create()->parties()->create(['name' => 'Other']);
        $invalid = $payload;
        $invalid['save_rules'][0]['target_id'] = $foreign->id;
        $this->postJson('/api/v1/import/confirm', $invalid)->assertUnprocessable();
        $this->assertSame(0, $this->user->transactions()->count());
        $this->assertSame(0, $this->user->importRules()->count());
        $this->postJson('/api/v1/import/confirm', $payload)->assertOk();
        $this->assertDatabaseHas('import_rules', ['owner_id' => $this->user->id, 'owner_type' => User::class, 'source_session_id' => $session['id']]);
        [$next] = $this->analyze();
        $this->assertSame($alias['target_id'], $next['suggestions'][0]['party_id']);
    }

    public function test_assistant_lists_creates_and_toggles_rules_through_confirmation(): void
    {
        $chat = \App\Models\ChatSession::create(['owner_type' => User::class, 'owner_id' => $this->user->id]);
        $context = \Whilesmart\Agents\ValueObjects\ToolContext::forUser($this->user, 'en', ['chat_session_id' => $chat->id]);
        $tools = app(\Whilesmart\Agents\Registries\ToolRegistry::class);
        $this->app->instance(\App\Ai\BlockCollector::class, new \App\Ai\BlockCollector());
        $tools->resolve('create_import_rule')->handle($this->alias(), $context);
        $action = \App\Models\AgentProposedAction::latest('id')->firstOrFail();
        $this->assertSame(0, $this->user->importRules()->count());
        $this->actingAs($this->user)->postJson("/api/v1/ai/chats/{$chat->id}/actions/{$action->id}/confirm")->assertOk();
        $rule = $this->user->importRules()->firstOrFail();
        $listed = $tools->resolve('list_import_rules')->handle([], $context);
        $this->assertSame($rule->id, $listed['rows'][0]['id']);
        $this->assertSame($rule->target_id, $listed['rows'][0]['target_id']);
        $this->app->instance(\App\Ai\BlockCollector::class, new \App\Ai\BlockCollector());
        $tools->resolve('toggle_import_rule')->handle(['rule_id' => $rule->id, 'active' => false], $context);
        $action = \App\Models\AgentProposedAction::latest('id')->firstOrFail();
        $this->postJson("/api/v1/ai/chats/{$chat->id}/actions/{$action->id}/confirm", ['overrides' => ['rule_id' => 999999]])->assertOk();
        $this->assertFalse($rule->refresh()->active);
    }

    public function test_inactive_and_deleted_rules_do_not_match(): void
    {
        $other = User::factory()->create();
        $other->importRules()->create(['kind' => 'skip', 'match_field' => 'any', 'match_mode' => 'exact', 'pattern' => '  momo   user charges  ']);
        $rule = $this->createRule($this->alias(['active' => false]));
        [$session] = $this->analyze();
        $this->assertNull($session['suggestions'][0]['party_id']);
        $this->assertFalse($session['suggestions'][0]['skip']);
        $this->patchJson('/api/v1/import-rules/' . $rule['id'], ['active' => true])->assertOk();
        $this->deleteJson('/api/v1/import-rules/' . $rule['id'])->assertOk();
        [$session] = $this->analyze();
        $this->assertNull($session['suggestions'][0]['party_id']);
        $this->assertFalse($session['suggestions'][0]['skip']);
    }
}
