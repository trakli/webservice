<?php

namespace Tests\Feature;

use App\Mail\GenericMail;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_user_can_request_password_reset_code()
    {
        User::factory()->create([
            'email' => 'user@example.com',
        ]);

        Mail::fake();

        $response = $this->postJson('/api/v1/password/reset-code', [
            'email' => 'user@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'If this email matches a record, a password reset code has been sent.',
            ]);

        Mail::assertQueued(GenericMail::class, function ($mail) {
            return $mail->hasTo('user@example.com') && $mail->subject === 'Password Reset Code';
        });
    }

    public function test_api_user_receives_validation_error_when_email_is_invalid()
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/password/reset-code', [
            'email' => 'invalid-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }

    public function test_api_user_can_reset_password_with_code()
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('oldpassword'),
        ]);

        VerificationCode::create([
            'contact' => 'user@example.com',
            'purpose' => 'password_reset',
            'code' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(15),
        ]);

        Mail::fake();

        $response = $this->postJson('/api/v1/password/reset', [
            'email' => 'user@example.com',
            'code' => 123456,
            'new_password' => 'newpassword123',
            'new_password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Password has been reset successfully.',
            ]);

        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));

        Mail::assertNotSent(GenericMail::class);
    }

    public function test_api_user_receives_error_if_code_is_invalid()
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
        ]);

        VerificationCode::create([
            'contact' => 'user@example.com',
            'purpose' => 'password_reset',
            'code' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(15),
        ]);

        Mail::fake();

        $response = $this->postJson('/api/v1/password/reset', [
            'email' => 'user@example.com',
            'code' => 654321,
            'new_password' => 'newpassword123',
            'new_password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Invalid or expired code.',
            ]);

        Mail::assertNotSent(GenericMail::class);
    }
}
