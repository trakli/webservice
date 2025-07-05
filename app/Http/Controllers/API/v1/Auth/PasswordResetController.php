<?php

namespace App\Http\Controllers\API\v1\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;
use Whilesmart\LaravelUserAuthentication\Events\PasswordResetCodeGeneratedEvent;
use Whilesmart\LaravelUserAuthentication\Events\PasswordResetCompleteEvent;
use Whilesmart\LaravelUserAuthentication\Models\User;
use Whilesmart\LaravelUserAuthentication\Models\VerificationCode;
use Whilesmart\LaravelUserAuthentication\Traits\ApiResponse;
use Whilesmart\LaravelUserAuthentication\Traits\Loggable;

#[OA\Tag(name: 'Authentication', description: 'Endpoints for password reset')]
class PasswordResetController extends Controller
{
    use ApiResponse, Loggable;

    #[OA\Post(
        path: '/password/reset-code',
        summary: 'Send password reset code',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [
                    new OA\Property(
                        property: 'email',
                        description: 'The email address of the user',
                        type: 'string',
                        format: 'email'
                    ),
                ]
            )
        ),
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Password reset code sent successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'message',
                            type: 'string',
                            example: 'Password reset code sent successfully.'
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error'
            ),
        ]
    )]
    public function sendPasswordResetCode(Request $request): JsonResponse
    {
        // Rate limiting
        if (RateLimiter::tooManyAttempts('password-reset:'.$request->ip(), 5)) {
            return $this->failure('Too many attempts, please try again later.', 429);
        }

        RateLimiter::hit('password-reset:'.$request->ip(), 300);

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return $this->failure('Validation failed.', 422, [$validator->errors()]);
        }

        if (User::where('email', $request->email)->first() == null) {
            $this->error("Email $request->email does not exist in our records");
        } else {
            $email = $request->email;
            $verificationCode = random_int(100000, 999999);
            $expiresAt = now()->addMinutes(15);
            $this->error("Email $request->email does exist in our records");

            VerificationCode::updateOrCreate(
                ['contact' => $email, 'purpose' => 'password_reset'],
                ['code' => Hash::make($verificationCode), 'expires_at' => $expiresAt]
            );

            PasswordResetCodeGeneratedEvent::dispatch($email, $verificationCode);
        }

        return $this->success([], 'If this email matches a record, a password reset code has been sent.');
    }

    #[OA\Post(
        path: '/password/reset',
        summary: 'Reset password using verification code',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'code', 'new_password'],
                properties: [
                    new OA\Property(
                        property: 'email',
                        description: 'The email address of the user',
                        type: 'string',
                        format: 'email'
                    ),
                    new OA\Property(
                        property: 'code',
                        description: 'The verification code sent to the user',
                        type: 'integer'
                    ),
                    new OA\Property(
                        property: 'new_password',
                        description: 'The new password for the user',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'new_password_confirmation',
                        description: 'Confirmation of the new password',
                        type: 'string'
                    ),
                ]
            )
        ),
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Password reset successful',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'message',
                            type: 'string',
                            example: 'Password has been reset successfully.'
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error'
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid or expired code'
            ),
        ]
    )]
    public function resetPasswordWithCode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'code' => 'required|integer',
            'new_password' => 'required|string|min:8|confirmed',
            'new_password_confirmation' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->failure('Validation failed.', 422, [$validator->errors()]);
        }

        if (User::where('email', $request->email)->first() == null) {
            $this->error("Email $request->email does not exist in our records");

            return $this->failure('Invalid or expired code.', 400);
        }
        $codeEntry = VerificationCode::where('contact', $request->email)
            ->where('purpose', 'password_reset')
            ->first();

        if (! $codeEntry) {
            return $this->failure('Invalid or expired code.', 400);
        }

        if (! Hash::check($request->code, $codeEntry->code) || $codeEntry->isExpired()) {
            return $this->failure('Invalid or expired code.', 400);
        }

        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->new_password);
        $user->save();

        PasswordResetCompleteEvent::dispatch($user);

        $codeEntry->delete();

        return $this->success([], 'Password has been reset successfully.');
    }
}
