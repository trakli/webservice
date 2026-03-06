<?php

namespace App\Http\Controllers\API\v1\Admin;

use App\Events\AccountDeleted;
use App\Http\Controllers\API\ApiController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * @OA\Tag(name="Admin", description="Admin operations")
 */
class UserController extends ApiController
{
    #[OA\Get(
        path: '/admin/users',
        summary: 'List all users',
        tags: ['Admin'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of users'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $query = User::query();
        $search = $request->input('search');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        $users = $query->paginate($request->input('per_page', 15));

        return $this->success($users);
    }

    #[OA\Get(
        path: '/admin/users/{id}',
        summary: 'Get a specific user',
        tags: ['Admin'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'User details'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'User not found'),
        ]
    )]
    public function show(int $userId): JsonResponse
    {
        $user = User::find($userId);

        if (! $user) {
            return $this->failure('User not found.', 404);
        }

        return $this->success($user);
    }

    #[OA\Delete(
        path: '/admin/users/{id}',
        summary: 'Delete a user account',
        tags: ['Admin'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'reason', type: 'string', description: 'Reason for account deletion', maxLength: 1000),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'User deleted successfully'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'User not found'),
        ]
    )]
    public function destroy(Request $request, int $userId): JsonResponse
    {
        $user = User::find($userId);

        if (! $user) {
            return $this->failure('User not found.', 404);
        }

        $email = $user->email;
        $name = $user->first_name . ' ' . $user->last_name;
        $adminName = $request->user()->first_name . ' ' . $request->user()->last_name;
        $reason = $request->input('reason', 'No reason provided');

        $user->tokens()->delete();
        $user->delete();

        $deletionReason = "Deleted by: {$adminName}\n\nReason: {$reason}";
        AccountDeleted::dispatch($name, $email, $deletionReason, 'Admin');

        return $this->success(null, 'User deleted successfully.');
    }
}
