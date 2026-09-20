<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Whilesmart\Admin\Mail\TemplateMail;

class RegistrationWelcomeEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // .env.example points verification at SmartPings with no credentials, so registration
        // cannot complete unless a test picks the provider it means to exercise.
        Config::set('user-authentication.verification.provider', 'default');
        Config::set('user-authentication.verification.require_email_verification', false);
    }

    public function test_registration_queues_the_shared_welcome_template(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/register', [
            'email' => 'new@example.com',
            'first_name' => 'New',
            'last_name' => 'User',
            'password' => 'password123',
        ])->assertCreated();

        Mail::assertQueued(
            TemplateMail::class,
            fn (TemplateMail $mail) => $mail->hasTo('new@example.com') && $mail->mailSubject === 'Welcome to Trakli, New'
        );
    }

    public function test_registration_sends_nothing_when_the_welcome_template_is_disabled(): void
    {
        Mail::fake();
        Config::set('admin.templates.welcome.enabled', false);

        $this->postJson('/api/v1/register', [
            'email' => 'quiet@example.com',
            'first_name' => 'Quiet',
            'last_name' => 'User',
            'password' => 'password123',
        ])->assertCreated();

        Mail::assertNotQueued(TemplateMail::class);
    }
}
