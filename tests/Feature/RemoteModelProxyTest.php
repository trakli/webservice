<?php

namespace Tests\Feature;

use App\Ai\Billing\RemoteModelGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;
use Whilesmart\AgentMetrics\Facades\TokenMeter;

class RemoteModelProxyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_or_forged_owner_grant_is_rejected(): void
    {
        $this->postJson('/api/v1/internal/model', [])->assertUnauthorized()->assertJsonPath('success', false);
        $this->withToken('forged')->postJson('/api/v1/internal/model', [])->assertUnauthorized();
    }

    public function test_expired_grants_are_rejected_before_a_model_call(): void
    {
        $token = Crypt::encryptString(json_encode([
            'scope' => 'smartql.model', 'owner_id' => 1,
            'request_id' => (string) Str::uuid(), 'expires_at' => now()->subSecond()->timestamp,
        ], JSON_THROW_ON_ERROR));
        $this->withToken($token)->postJson('/api/v1/internal/model', [])->assertUnauthorized();
    }

    public function test_completed_model_calls_replay_and_cannot_change_payload(): void
    {
        $owner = User::factory()->create();
        $token = app(RemoteModelGrant::class)->issue($owner);
        $grant = json_decode(Crypt::decryptString($token), true);
        $input = ['call_id' => (string) Str::uuid(), 'max_tokens' => 50, 'messages' => [['role' => 'user', 'content' => 'question']]];
        $usage = TokenMeter::record(owner: $owner, provider: 'groq', model: 'stored-model', usage: [], operation: 'smartql.model', metadata: ['call_id' => $grant['request_id'].':'.$input['call_id'],
                'payload_hash' => hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)), 'text' => 'Stored answer']);
        $this->withToken($token)->postJson('/api/v1/internal/model', $input)
            ->assertOk()->assertJsonPath('data.text', 'Stored answer');
        $this->assertDatabaseCount($usage->getTable(), 1);
        $input['messages'][0]['content'] = 'changed question';
        $this->withToken($token)->postJson('/api/v1/internal/model', $input)->assertConflict();
    }

    public function test_accounted_calls_without_recoverable_text_are_not_run_again(): void
    {
        $owner = User::factory()->create();
        $token = app(RemoteModelGrant::class)->issue($owner);
        $grant = json_decode(Crypt::decryptString($token), true);
        $input = ['call_id' => (string) Str::uuid(), 'max_tokens' => 50, 'messages' => [['role' => 'user', 'content' => 'question']]];
        TokenMeter::record(owner: $owner, provider: 'groq', model: 'stored-model', usage: [], operation: 'smartql.model', metadata: ['call_id' => $grant['request_id'].':'.$input['call_id'],
                'payload_hash' => hash('sha256', json_encode($input, JSON_THROW_ON_ERROR))]);
        $this->withToken($token)->postJson('/api/v1/internal/model', $input)->assertConflict();
    }
}
