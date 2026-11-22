<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Tests\CreatesApplication;
use Whilesmart\Engagement\Models\EngagementEvent;
use Whilesmart\Roles\Models\Role;

class EngagementIdentityTest extends TestCase
{
    use CreatesApplication;
    use RefreshDatabase;

    public function test_identification_requires_authentication_and_a_visitor_id(): void
    {
        $this->postJson('/api/v1/engagement/identify', ['visitor_id' => 'browser-1'])->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/engagement/identify', [])->assertUnprocessable();
    }

    public function test_sign_in_links_visits_and_preserves_the_original_source_in_admin_metrics(): void
    {
        config([
            'engagement.clients.dashboard.site_key' => 'fixture-dashboard-key',
            'engagement.clients.dashboard.allowed_origins' => ['https://dashboard.example.test'],
        ]);
        $this->visit('first', 'newsletter', now()->subDays(40)->toIso8601String());
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/engagement/identify', ['visitor_id' => 'browser-1'])
            ->assertOk()->assertJsonPath('data.linked', 1);
        $this->visit('second', 'direct', now()->toIso8601String());

        $events = EngagementEvent::query()->where('name', 'page.view')->get();
        $this->assertCount(2, $events);
        $this->assertSame([$user->id], $events->pluck('subject_id')->unique()->values()->all());
        $this->assertSame([$user->getMorphClass()], $events->pluck('subject_type')->unique()->values()->all());

        Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $user->assignRole('admin');
        $response = $this->getJson('/api/v1/admin/metrics?days=30&client=dashboard')->assertOk();
        $visitors = collect($response->json('data.groups'))->firstWhere('key', 'visitors');
        $metrics = collect($visitors['metrics'])->keyBy('key');

        $this->assertSame(1, $metrics['identified_visitors']['value']);
        $this->assertSame([['label' => 'newsletter', 'value' => 1]], $metrics['identified_by_source']['rows']);
    }

    private function visit(string $session, string $source, string $occurredAt): void
    {
        $this->postJson('/api/v1/engagement/events', ['events' => [[
            'name' => 'page.view',
            'visitor_id' => 'browser-1',
            'session_id' => $session,
            'source' => $source,
            'occurred_at' => $occurredAt,
        ]]], [
            'Origin' => 'https://dashboard.example.test',
            'X-Engagement-Site-Key' => 'fixture-dashboard-key',
        ])->assertAccepted();
    }
}
