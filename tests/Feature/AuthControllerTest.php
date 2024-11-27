<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    public function test_api_user_can_register_successfully()
    {
        $response = $this->postJson('/api/v1/register', [
            'email' => $this->faker->unique()->safeEmail,
            'first_name' => $this->faker->firstName,
            'last_name' => $this->faker->lastName,
            'username' => $this->faker->userName,
            'phone' => $this->faker->phoneNumber,
            'password' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'user',
                    'token',
                ],
                'message',
            ]);

        $this->assertDatabaseHas('users', ['email' => $response->json('data.user.email')]);
    }

    public function test_api_user_receives_register_validation_error_when_required_fields_are_missing()
    {
        $response = $this->postJson('/api/v1/register', []);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => [
                    [
                        'email' => ['The email field is required.'],
                        'first_name' => ['The first name field is required.'],
                        'password' => ['The password field is required.'],
                    ],
                ],
            ]);
    }

    public function test_api_user_can_login_successfully_with_email()
    {
        User::factory()->create([
            'email' => 'user1@trakli.io',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'user1@trakli.io',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'token',
                    'user',
                ],
                'message',
            ]);
    }

    public function test_api_user_can_login_successfully_with_username()
    {
        User::factory()->create([
            'username' => 'testuser',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'username' => 'testuser',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'token',
                    'user',
                ],
                'message',
            ]);
    }

    public function test_api_user_can_login_successfully_with_phone()
    {
        User::factory()->create([
            'phone' => '1234567890',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'phone' => '1234567890',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'token',
                    'user',
                ],
                'message',
            ]);
    }

    public function test_api_user_login_failed_with_invalid_credentials()
    {
        User::factory()->create([
            'email' => 'user1@trakli.io',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'user1@trakli.io',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid credentials',
            ]);
    }

    public function test_api_user_login_failed_with_unregistered_user()
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'nonexistent@trakli.io',
            'password' => 'password123',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid credentials',
            ]);
    }

    public function test_api_user_receives_login_validation_error_when_required_fields_are_missing()
    {
        $response = $this->postJson('/api/v1/login', []);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => [
                    [
                        'email' => ['The email field is required when none of phone / username are present.'],
                        'phone' => ['The phone field is required when none of email / username are present.'],
                        'username' => ['The username field is required when none of email / phone are present.'],
                        'password' => ['The password field is required.'],
                    ],
                ],
            ]);
    }
}
