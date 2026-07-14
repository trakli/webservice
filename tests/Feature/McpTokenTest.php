<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpTokenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_a_user_can_create_a_token_and_sees_the_secret_once(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/ai/mcp/tokens', ['name' => 'Claude Desktop'])
            ->assertCreated();

        $response->assertJsonPath('data.name', 'Claude Desktop');
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_listing_returns_mcp_tokens_without_the_secret(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/ai/mcp/tokens', ['name' => 'Cursor']);

        // A session-style token (different ability) must not appear in the list.
        $this->user->createToken('login', ['*']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/ai/mcp/tokens')
            ->assertOk();

        $names = array_column($response->json('data'), 'name');
        $this->assertContains('Cursor', $names);
        $this->assertNotContains('login', $names);
        $this->assertArrayNotHasKey('token', $response->json('data')[0]);
    }

    public function test_a_user_can_revoke_a_token(): void
    {
        $id = $this->actingAs($this->user)
            ->postJson('/api/v1/ai/mcp/tokens', ['name' => 'Temp'])
            ->json('data.id');

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/ai/mcp/tokens/{$id}")
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $id]);
    }

    public function test_a_user_cannot_revoke_another_users_token(): void
    {
        $otherId = $this->user->createToken('mine', ['mcp'])->accessToken->id;
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->deleteJson("/api/v1/ai/mcp/tokens/{$otherId}")
            ->assertNotFound();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherId]);
    }
}
