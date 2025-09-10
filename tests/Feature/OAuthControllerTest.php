<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class OAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_login_redirects_successfully()
    {
        // Mock the Socialite provider
        $provider = \Mockery::mock('Laravel\Socialite\Two\GoogleProvider');
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        // Call the login endpoint
        $response = $this->getJson('/api/v1/oauth/google/login');

        // Assert the response
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['url', 'message'],
            ]);

        $this->assertStringContainsString('https://accounts.google.com/o/oauth2/auth', $response->json('data.url'));
    }
}
