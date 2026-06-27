<?php

namespace Tests\Feature\Admin;

use App\Mail\OutreachMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Whilesmart\Roles\Models\Role;

class OutreachControllerTest extends TestCase
{
    use RefreshDatabase;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['first_name' => 'Ada']);
        Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $this->admin->assignRole('admin');
    }

    public function test_admin_sends_personalized_outreach_with_cta(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/outreach/send', [
            'subject' => 'Hi {{first_name}}',
            'body' => 'Welcome {{first_name}}, take a look.',
            'cta_label' => 'Open Trakli',
            'cta_url' => 'https://trakli.app/dashboard',
            'audience' => 'test',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.sent', 1);

        Mail::assertSent(OutreachMail::class, function (OutreachMail $mail) {
            return $mail->subject === 'Hi Ada'
                && str_contains($mail->body, 'Welcome Ada')
                && $mail->ctaLabel === 'Open Trakli'
                && $mail->hasTo($this->admin->email);
        });
    }

    public function test_cta_url_is_required_with_a_label(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/outreach/send', [
            'subject' => 'Hello',
            'body' => 'Body',
            'cta_label' => 'Open',
            'audience' => 'all',
        ]);

        $response->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_admin_previews_the_rendered_email(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/outreach/preview', [
            'subject' => 'Hi {{first_name}}',
            'body' => 'Welcome {{first_name}}',
            'cta_label' => 'Open',
            'cta_url' => 'https://trakli.app',
        ]);

        $response->assertStatus(200);
        $html = $response->json('data.html');
        $this->assertStringContainsString('Hi Ada', $html);
        $this->assertStringContainsString('Open', $html);
        $this->assertStringContainsString('https://trakli.app', $html);
    }

    public function test_admin_sends_to_specific_users(): void
    {
        Mail::fake();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $other = User::factory()->create();

        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/outreach/send', [
            'subject' => 'Hello',
            'body' => 'Body',
            'audience' => 'specific',
            'user_ids' => [$a->id, $b->id],
        ]);

        $response->assertStatus(200)->assertJsonPath('data.sent', 2);
        Mail::assertSent(OutreachMail::class, fn (OutreachMail $m) => $m->hasTo($a->email));
        Mail::assertSent(OutreachMail::class, fn (OutreachMail $m) => $m->hasTo($b->email));
        Mail::assertNotSent(OutreachMail::class, fn (OutreachMail $m) => $m->hasTo($other->email));
    }

    public function test_specific_audience_requires_user_ids(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/outreach/send', [
            'subject' => 'Hello',
            'body' => 'Body',
            'audience' => 'specific',
        ]);

        $response->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_admin_can_attach_a_file(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->admin)->post('/api/v1/admin/outreach/send', [
            'subject' => 'Hello',
            'body' => 'Body',
            'audience' => 'test',
            'files' => [UploadedFile::fake()->create('report.pdf', 5, 'application/pdf')],
        ]);

        $response->assertStatus(200);
        Mail::assertSent(OutreachMail::class, function (OutreachMail $mail) {
            return count($mail->files) === 1 && $mail->files[0]['name'] === 'report.pdf';
        });
    }

    public function test_admin_uploads_an_outreach_image(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->admin)->post('/api/v1/admin/outreach/media', [
            'image' => UploadedFile::fake()->image('banner.jpg'),
        ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.url'));
    }

    public function test_the_body_renders_markdown(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/outreach/preview', [
            'subject' => 'Hi',
            'body' => "Hello **there**\n\n- one\n- two",
        ]);

        $response->assertStatus(200);
        $html = $response->json('data.html');
        $this->assertStringContainsString('<strong>there</strong>', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
    }

    public function test_preview_embeds_the_hero_image(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/outreach/preview', [
            'subject' => 'Hi',
            'body' => 'Body',
            'image_url' => 'https://cdn.example.com/banner.jpg',
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('https://cdn.example.com/banner.jpg', $response->json('data.html'));
    }

    public function test_a_send_is_recorded_and_listed(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)->postJson('/api/v1/admin/outreach/send', [
            'subject' => 'June update',
            'body' => 'Body',
            'audience' => 'test',
        ])->assertStatus(200);

        $this->assertDatabaseHas('outreaches', ['subject' => 'June update', 'audience' => 'test', 'sent' => 1]);

        $list = $this->actingAs($this->admin)->getJson('/api/v1/admin/outreach');
        $list->assertStatus(200);
        $this->assertSame('June update', $list->json('data.0.subject'));
    }

    public function test_non_admin_cannot_send_outreach(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/admin/outreach/send', [
            'subject' => 'Hello',
            'body' => 'Body',
            'audience' => 'all',
        ]);

        $response->assertStatus(403);
        Mail::assertNothingSent();
    }
}
