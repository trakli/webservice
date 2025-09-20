<?php

namespace App\Http\Documentation;

use OpenApi\Attributes as OA;

/**
 * OpenAPI Documentation for Laravel User Authentication Package
 *
 * This class contains all the API documentation that can be published
 * to your application for OpenAPI spec generation without needing
 * to publish the actual controllers.
 */
#[OA\Tag(name: 'Authentication', description: 'Endpoints for user authentication')]
class UserAuthOpenApiDocs
{
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
    public function register() {}

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
    public function login() {}

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
    public function logout() {}

    #[OA\Get(
        path: '/oauth/{driver}/login',
        summary: 'Get Oauth redirect URI',
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 200, description: 'URL generated'),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function oauthLogin() {}

    #[OA\Get(
        path: '/oauth/{driver}/callback',
        summary: 'Handles Oauth login callback',
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 200, description: ' '),
            new OA\Response(response: 500, description: 'Server error'),
        ]
    )]
    public function oauthCallback() {}

    #[OA\Post(
        path: '/send-verification-code',
        summary: 'Send verification code to email or phone',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['contact', 'type'],
                properties: [
                    new OA\Property(property: 'contact', type: 'string', description: 'Email address or phone number'),
                    new OA\Property(property: 'type', type: 'string', enum: ['email', 'phone'], description: 'Type of contact'),
                    new OA\Property(property: 'purpose', type: 'string', enum: ['registration', 'login'], description: 'Purpose of verification (optional, defaults to "registration")', example: 'registration'),
                ]
            )
        ),
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 200, description: 'Verification code sent successfully'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 429, description: 'Too many requests'),
        ]
    )]
    public function sendVerificationCode() {}

    #[OA\Post(
        path: '/verify-code',
        summary: 'Verify a code for email or phone',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['contact', 'code', 'type'],
                properties: [
                    new OA\Property(property: 'contact', type: 'string', description: 'Email address or phone number'),
                    new OA\Property(property: 'code', type: 'string', description: 'Verification code'),
                    new OA\Property(property: 'type', type: 'string', enum: ['email', 'phone'], description: 'Type of contact'),
                    new OA\Property(property: 'purpose', type: 'string', enum: ['registration', 'login'], description: 'Purpose of verification (optional, defaults to "registration")', example: 'registration'),
                ]
            )
        ),
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 200, description: 'Code verified successfully'),
            new OA\Response(response: 400, description: 'Invalid or expired code'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function verifyCode() {}
}
