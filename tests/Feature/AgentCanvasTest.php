<?php

namespace Tests\Feature;

use App\Ai\BlockCollector;
use App\Ai\Tools\Render\UpdateCanvasTool;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Whilesmart\Agents\ValueObjects\ToolContext;

class AgentCanvasTest extends TestCase
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

    private function tool(): UpdateCanvasTool
    {
        $this->app->instance(BlockCollector::class, new BlockCollector());

        return $this->app->make(UpdateCanvasTool::class);
    }

    private function context(): ToolContext
    {
        return ToolContext::forUser($this->user, 'en', ['chat_session_id' => $this->session->id]);
    }

    private function seedCanvas(): void
    {
        $this->session->messages()->create([
            'role' => ChatMessage::ROLE_ASSISTANT,
            'status' => ChatMessage::STATUS_COMPLETED,
            'result' => ['source' => 'agent', 'blocks' => [
                ['type' => 'canvas', 'title' => 'June Report', 'blocks' => [
                    ['type' => 'markdown', 'text' => 'Spending overview intro.'],
                    ['type' => 'chart', 'chart_hint' => 'donut', 'dataset_ref' => 'category_spending'],
                ]],
            ]],
        ]);
    }

    public function test_update_canvas_returns_prior_sections_and_opens_the_canvas(): void
    {
        $this->seedCanvas();

        $collector = new BlockCollector();
        $this->app->instance(BlockCollector::class, $collector);
        $tool = $this->app->make(UpdateCanvasTool::class);

        $out = $tool->handle([], $this->context());

        $this->assertIsString($out);
        $this->assertStringContainsString('June Report', $out);
        $this->assertStringContainsString('Spending overview intro.', $out);
        $this->assertStringContainsString('category_spending', $out);
        // The run is now in canvas mode under the prior title.
        $this->assertSame('June Report', $collector->canvasTitle());
    }

    public function test_update_canvas_can_retitle(): void
    {
        $this->seedCanvas();

        $collector = new BlockCollector();
        $this->app->instance(BlockCollector::class, $collector);
        $tool = $this->app->make(UpdateCanvasTool::class);

        $tool->handle(['title' => 'July Report'], $this->context());

        $this->assertSame('July Report', $collector->canvasTitle());
    }

    public function test_update_canvas_errors_without_a_prior_canvas(): void
    {
        $out = $this->tool()->handle([], $this->context());

        $this->assertIsArray($out);
        $this->assertArrayHasKey('error', $out);
    }
}
