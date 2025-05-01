<?php

namespace App\Http\Controllers\API\v1\Auth;

use App\Events\UserRegisteredEvent;
use App\Http\Controllers\API\ApiController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Authentication', description: 'Endpoints for user authentication')]
class AuthController extends ApiController
{
    #[OA\Post(
        path: '/register',
        tags: ['Authentication'],
        security: [],
        summary: 'Register a new user',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'first_name', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'johndoe@trakli.app'),
                    new OA\Property(property: 'first_name', type: 'string', example: 'John'),
                    new OA\Property(property: 'last_name', type: 'string', example: 'Doe'),
                    new OA\Property(property: 'username', type: 'string', example: 'johndoe'),
                    new OA\Property(property: 'phone', type: 'string', example: '+1234567890'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password123'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'User registered successfully'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function register(Request $request)
    {
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

        $response = [
            'user' => $user,
            'token' => $user->createToken('auth-token')->plainTextToken,
        ];

        return $this->success($response, 'User registered successfully', 201);
    }

    #[OA\Post(
        path: '/login',
        tags: ['Authentication'],
        security: [],
        summary: 'User login',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user1@trakli.app'),
                    new OA\Property(property: 'phone', type: 'string'),
                    new OA\Property(property: 'username', type: 'string', example: 'user1'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password123'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'User successfully logged in'),
            new OA\Response(response: 401, description: 'Invalid credentials'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required_without_all:phone,username|email',
            'phone' => 'required_without_all:email,username|string',
            'username' => 'required_without_all:email,phone|string',
            'password' => 'required|string|min:6',
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

            return $this->success([
                'token' => $user->createToken('auth-token')->plainTextToken,
                'token_type' => 'Bearer',
                'user' => auth()->user(),
            ], 'User successfully logged in', 200);
        } catch (\Exception $e) {
            Log::error('An error occurred during login: '.$e->getMessage(), ['exception' => $e]);

            return $this->failure('An error occurred during login', 500);
        }
    }
}
