<?php

namespace App\Http\Controllers\API\v1\Auth;

use App\Http\Controllers\API\ApiController;
use App\Models\User;
use App\Models\VerificationCode;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Authentication', description: 'Endpoints for password reset')]
class PasswordResetController extends ApiController
{
    public function __construct(private NotificationService $notificationService) {}

    #[OA\Post(
        path: '/password/reset-code',
        tags: ['Authentication'],
        security: [],
        summary: 'Send password reset code',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [
                    new OA\Property(
                        property: 'email',
                        type: 'string',
                        format: 'email',
                        description: 'The email address of the user'
                    ),
                ]
            )
        ),
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
    public function sendPasswordResetCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return $this->failure('Validation failed.', 422, [$validator->errors()]);
        }

        $email = $request->email;
        $verificationCode = random_int(100000, 999999);
        $expiresAt = now()->addMinutes(15);

        VerificationCode::updateOrCreate(
            ['contact' => $email, 'purpose' => 'password_reset'],
            ['code' => $verificationCode, 'expires_at' => $expiresAt]
        );

        $this->notificationService->sendEmailNotification([
            'to' => $email,
            'subject' => __('Password Reset Code'),
            'body' => __('Your password reset code for Trakli is: ').$verificationCode,
        ]);

        return $this->success([], 'Password reset code sent successfully.');
    }

    #[OA\Post(
        path: '/password/reset',
        tags: ['Authentication'],
        security: [],
        summary: 'Reset password using verification code',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'code', 'new_password'],
                properties: [
                    new OA\Property(
                        property: 'email',
                        type: 'string',
                        format: 'email',
                        description: 'The email address of the user'
                    ),
                    new OA\Property(
                        property: 'code',
                        type: 'integer',
                        description: 'The verification code sent to the user'
                    ),
                    new OA\Property(
                        property: 'new_password',
                        type: 'string',
                        description: 'The new password for the user'
                    ),
                ]
            )
        ),
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
    public function resetPasswordWithCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'code' => 'required|integer',
            'new_password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return $this->failure('Validation failed.', 422, [$validator->errors()]);
        }

        $codeEntry = VerificationCode::where('contact', $request->email)
            ->where('purpose', 'password_reset')
            ->first();

        if (! $codeEntry || $codeEntry->code != $request->code || $codeEntry->isExpired()) {
            return $this->failure('Invalid or expired code.', 400);
        }

        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->new_password);
        $user->save();

        $this->notificationService->sendEmailNotification([
            'to' => $request->email,
            'subject' => __('Your password was changed'),
            'body' => __('Password has been reset successfully. If you did nt make this change, please contact us.'),
        ]);

        $codeEntry->delete();

        return $this->success([], 'Password has been reset successfully.');
    }
}
