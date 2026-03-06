<?php

namespace App\Http\Controllers\API\v1;

use App\Events\AccountDeleted;
use App\Http\Controllers\API\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * @OA\Tag(name="Account", description="Account management operations")
 */
class AccountController extends ApiController
{
    #[OA\Delete(
        path: '/account',
        summary: 'Delete authenticated user account',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['confirm_delete'],
                properties: [
                    new OA\Property(
                        property: 'confirm_delete',
                        type: 'boolean',
                        description: 'Must be true to confirm deletion'
                    ),
                    new OA\Property(property: 'reason', type: 'string', description: 'Reason for account deletion', maxLength: 1000),
                ]
            )
        ),
        tags: ['Account'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Account deleted successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Account deleted successfully.'),
                        new OA\Property(property: 'data', type: 'null'),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function destroy(Request $request): JsonResponse
    {
        $validation = $this->validateRequest($request, [
            'confirm_delete' => 'required|accepted',
            'reason' => 'nullable|string|max:1000',
        ]);

        if (! $validation['isValidated']) {
            return $this->failure($validation['message'], $validation['code'], $validation['errors']);
        }

        $user = $request->user();

        $email = $user->email;
        $name = $user->first_name . ' ' . $user->last_name;
        $reason = $request->input('reason', 'No reason provided');

        $user->tokens()->delete();
        $user->delete();

        AccountDeleted::dispatch($name, $email, $reason);

        return $this->success(null, 'Account deleted successfully.');
    }
}
