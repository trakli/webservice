<?php

namespace Tests\Feature;

use App\Ai\Export\MarkdownDocumentExporter;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanvasExportTest extends TestCase
{
    use RefreshDatabase;

    private function blocks(): array
    {
        return [
            ['type' => 'markdown', 'text' => '## Overview\n\nSpending is **down**.'],
            ['type' => 'table', 'title' => 'By category', 'columns' => ['category', 'total'], 'rows' => [
                ['category' => 'Food', 'total' => 120],
                ['category' => 'Rent', 'total' => 500],
            ]],
            ['type' => 'kpi', 'items' => [
                ['label' => 'Net cash flow', 'value' => -320, 'currency' => 'USD'],
            ]],
        ];
    }

    public function test_markdown_exporter_renders_blocks(): void
    {
        $md = (new MarkdownDocumentExporter())->export($this->blocks(), 'June Report');

        $this->assertStringContainsString('# June Report', $md);
        $this->assertStringContainsString('## By category', $md);
        $this->assertStringContainsString('| Category | Total |', $md);
        $this->assertStringContainsString('| Food | 120 |', $md);
        $this->assertStringContainsString('- **Net cash flow:** -320 USD', $md);
    }

    public function test_owner_can_export_a_message_canvas_as_markdown(): void
    {
        $user = User::factory()->create();
        $session = ChatSession::create(['owner_type' => $user->getMorphClass(), 'owner_id' => $user->id]);
        $message = $session->messages()->create([
            'role' => ChatMessage::ROLE_ASSISTANT,
            'status' => ChatMessage::STATUS_COMPLETED,
            'result' => ['source' => 'agent', 'blocks' => [
                ['type' => 'markdown', 'text' => 'Intro'],
                ['type' => 'canvas', 'title' => 'Spending Report', 'blocks' => $this->blocks()],
            ]],
        ]);

        $response = $this->actingAs($user)
            ->get("/api/v1/ai/chats/{$session->id}/messages/{$message->id}/export?format=md");

        $response->assertStatus(200);
        $this->assertStringContainsString('text/markdown', $response->headers->get('content-type'));
        $this->assertStringContainsString('# Spending Report', $response->getContent());
    }

    public function test_non_owner_cannot_export(): void
    {
        $user = User::factory()->create();
        $session = ChatSession::create(['owner_type' => $user->getMorphClass(), 'owner_id' => $user->id]);
        $message = $session->messages()->create([
            'role' => ChatMessage::ROLE_ASSISTANT,
            'result' => ['blocks' => [['type' => 'canvas', 'title' => 'x', 'blocks' => []]]],
        ]);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->get("/api/v1/ai/chats/{$session->id}/messages/{$message->id}/export?format=md")
            ->assertStatus(404);
    }

    public function test_unsupported_format_is_rejected(): void
    {
        $user = User::factory()->create();
        $session = ChatSession::create(['owner_type' => $user->getMorphClass(), 'owner_id' => $user->id]);
        $message = $session->messages()->create([
            'role' => ChatMessage::ROLE_ASSISTANT,
            'result' => ['blocks' => [['type' => 'canvas', 'title' => 'x', 'blocks' => []]]],
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/ai/chats/{$session->id}/messages/{$message->id}/export?format=xyz")
            ->assertStatus(422);
    }
}
