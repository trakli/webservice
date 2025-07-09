<?php

namespace App\Http\Controllers\API\v1\Auth;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;
use Whilesmart\LaravelUserAuthentication\Events\UserLoggedInEvent;
use Whilesmart\LaravelUserAuthentication\Events\UserLoggedOutEvent;
use Whilesmart\LaravelUserAuthentication\Events\UserRegisteredEvent;
use Whilesmart\LaravelUserAuthentication\Traits\ApiResponse;
use Whilesmart\LaravelUserAuthentication\Traits\Loggable;

#[OA\Tag(name: 'Authentication', description: 'Endpoints for user authentication')]
class AuthController extends Controller
{
    use ApiResponse, Loggable;

    #[OA\Post(
        path: '/register',
        summary: 'Register a new user',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'first_name', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'first_name', type: 'string'),
                    new OA\Property(property: 'last_name', type: 'string'),
                    new OA\Property(property: 'username', type: 'string'),
                    new OA\Property(property: 'phone', type: 'string'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                ]
            )
        ),
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 201, description: 'User registered successfully'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function register(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|string|email|max:255|unique:users',
                'first_name' => 'required|string|max:255',
                'last_name' => 'string|max:255',
                'username' => 'string|max:255',
                'phone' => 'string|max:255',
                'password' => 'required|string|min:8',
            ]);

            if ($validator->fails()) {
                return $this->failure('Validation failed.', 422, [$validator->errors()]);
            }

            $user_data = $request->only(['first_name', 'last_name', 'email', 'password', 'phone', 'username']);
            $user_data['password'] = Hash::make($request->password);

            $user = User::create($user_data);
            UserRegisteredEvent::dispatch($user);
            $this->info("New user with email $request->email just registered ");

            $response = [
                'user' => $user,
                'token' => $user->createToken('auth-token')->plainTextToken,
            ];

            return $this->success($response, 'User registered successfully', 201);
        } catch (\Exception $e) {
            $this->error($e);

            return $this->failure('An error occurred', 500);
        }
    }

    #[OA\Post(
        path: '/login',
        summary: 'User login',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'phone', type: 'string'),
                    new OA\Property(property: 'username', type: 'string'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                ]
            )
        ),
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 200, description: 'User successfully logged in'),
            new OA\Response(response: 401, description: 'Invalid credentials'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required_without_all:phone,username|email',
            'phone' => 'required_without_all:email,username|string',
            'username' => 'required_without_all:email,phone|string',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return $this->failure('Validation failed.', 422, [$validator->errors()]);
        }

        $identifier_field = $request->has('email') ? 'email'
            : ($request->has('phone') ? 'phone' : 'username');

        $credentials = [
            $identifier_field => $request->$identifier_field,
            'password' => $request->password,
        ];

        try {
            $user = User::where($identifier_field, $credentials[$identifier_field])->first();

            if (! $user || ! auth()->attempt($credentials)) {
                return $this->failure('Invalid credentials', 401);
            }

            UserLoggedInEvent::dispatch($user);

            return $this->success([
                'token' => $user->createToken('auth-token')->plainTextToken,
                'token_type' => 'Bearer',
                'user' => auth()->user(),
            ], 'User successfully logged in', 200);
        } catch (\Exception $e) {
            $this->error('An error occurred during login: '.$e->getMessage(), ['exception' => $e]);

            return $this->failure('An error occurred during login', 500);
        }
    }

    #[OA\Post(
        path: '/logout',
        summary: 'User logout',
        security: [
            ['sanctum' => []],
        ],
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 200, description: 'User successfully logged out'),
            new OA\Response(response: 401, description: 'Invalid credentials'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();
        UserLoggedOutEvent::dispatch($user);

        return $this->success([], 'User has been logged out successfully');
    }
}
