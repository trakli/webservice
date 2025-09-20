<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegistrationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private array $validRegistrationData = [
        'email' => 'test@example.com',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'password' => 'password123',
    ];

    /** @test */
    public function user_cannot_register_when_email_verification_required_and_not_verified()
    {
        // Enable email verification with SmartPings
        Config::set('user-authentication.verification.require_email_verification', true);
        Config::set('user-authentication.verification.provider', 'smartpings');
        Config::set('user-authentication.verification.self_managed', false);
        Config::set('user-authentication.smartpings.client_id', 'test_client_id');
        Config::set('user-authentication.smartpings.secret_id', 'test_secret_id');

        // Mock SmartPings to return unverified
        Http::fake([
            'smartpings.com/*' => Http::response([
                'success' => false,
                'message' => 'Email not verified',
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/register', $this->validRegistrationData);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Email verification required. Please verify your email first.',
            ]);

        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    /** @test */
    public function user_can_register_when_email_verification_required_and_verified()
    {
        // Enable email verification with SmartPings
        Config::set('user-authentication.verification.require_email_verification', true);
        Config::set('user-authentication.verification.provider', 'smartpings');
        Config::set('user-authentication.verification.self_managed', false);
        Config::set('user-authentication.smartpings.client_id', 'test_client_id');
        Config::set('user-authentication.smartpings.secret_id', 'test_secret_id');

        // Mock SmartPings to return verified
        Http::fake([
            'smartpings.com/*' => Http::response([
                'success' => true,
                'message' => 'Email verified',
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/register', $this->validRegistrationData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user',
                    'token',
                ],
            ]);

        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    }

    /** @test */
    public function user_cannot_register_when_phone_verification_required_and_not_verified()
    {
        // Enable phone verification with SmartPings
        Config::set('user-authentication.verification.require_phone_verification', true);
        Config::set('user-authentication.verification.provider', 'smartpings');
        Config::set('user-authentication.verification.self_managed', false);
        Config::set('user-authentication.smartpings.client_id', 'test_client_id');
        Config::set('user-authentication.smartpings.secret_id', 'test_secret_id');

        $registrationData = array_merge($this->validRegistrationData, [
            'phone' => '+1234567890',
        ]);

        // Mock SmartPings to return phone not verified
        Http::fake([
            'smartpings.com/*' => Http::response([
                'success' => false,
                'message' => 'Phone not verified',
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/register', $registrationData);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Phone verification required. Please verify your phone first.',
            ]);

        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    /** @test */
    public function user_can_register_when_phone_verification_required_and_verified()
    {
        // Enable phone verification with SmartPings
        Config::set('user-authentication.verification.require_phone_verification', true);
        Config::set('user-authentication.verification.provider', 'smartpings');
        Config::set('user-authentication.verification.self_managed', false);
        Config::set('user-authentication.smartpings.client_id', 'test_client_id');
        Config::set('user-authentication.smartpings.secret_id', 'test_secret_id');

        $registrationData = array_merge($this->validRegistrationData, [
            'phone' => '+1234567890',
        ]);

        // Mock SmartPings to return phone verified
        Http::fake([
            'smartpings.com/*' => Http::response([
                'success' => true,
                'message' => 'Phone verified',
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/register', $registrationData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user',
                    'token',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'phone' => '+1234567890',
        ]);
    }
}
