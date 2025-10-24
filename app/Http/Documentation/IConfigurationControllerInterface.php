<?php

namespace App\Http\Documentation;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

interface IConfigurationControllerInterface
{
    #[OA\Post(
        path: '/configurations',
        summary: 'Add a new  configuration',
        security: [
            ['bearerAuth' => []],
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['key', 'value', 'type'],
                properties: [
                    new OA\Property(property: 'key', type: 'string', example: 'theme_preference'),
                    new OA\Property(property: 'value', type: 'object', example: '{"theme": "dark", "color": "#333333"}'),
                    new OA\Property(property: 'type', type: 'string', enum: ['string', 'int', 'float', 'bool', 'array', 'json', 'date'], example: 'string'),
                    new OA\Property(property: 'client_id', description: 'Unique identifier for your local client', type: 'string',
                        format: 'string', example: '245cb3df-df3a-428b-a908-e5f74b8d58a3:245cb3df-df3a-428b-a908-e5f74b8d58a4'),
                ]
            )
        ),
        tags: ['Configuration'],
        responses: [
            new OA\Response(response: 201, description: 'Configuration added successfully'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse;

    #[OA\Put(
        path: '/configurations/{key}',
        summary: 'Update an existing user configuration',
        security: [
            ['bearerAuth' => []],
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['value'],
                properties: [
                    new OA\Property(property: 'value', type: 'object', example: '{"theme": "light", "color": "#ffffff"}'),
                    new OA\Property(property: 'type', type: 'string', enum: ['string', 'int', 'float', 'bool', 'array', 'json', 'date'], example: 'string'),
                    new OA\Property(property: 'client_id', description: 'Unique identifier for your local client', type: 'string', format: 'string', example: '245cb3df-df3a-428b-a908-e5f74b8d58a3:245cb3df-df3a-428b-a908-e5f74b8d58a4'),
                ]
            )
        ),
        tags: ['Configuration'],
        parameters: [
            new OA\Parameter(
                name: 'key',
                description: 'Configuration key',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Configuration updated successfully'),
            new OA\Response(response: 404, description: 'Configuration not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, $key): JsonResponse;

    #[OA\Delete(
        path: '/configurations/{key}',
        summary: 'Delete a user configuration',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Configuration'],
        parameters: [
            new OA\Parameter(
                name: 'key',
                description: 'Configuration key',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Configuration deleted successfully'),
            new OA\Response(response: 404, description: 'Configuration not found'),
        ]
    )]
    public function destroy(Request $request, $key): JsonResponse;

    #[OA\Get(
        path: '/configurations/{key}',
        summary: 'Get a single configuration',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Configuration'],
        parameters: [
            new OA\Parameter(
                name: 'key',
                description: 'Configuration key',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Configuration loaded successfully'),
            new OA\Response(response: 404, description: 'Configuration not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function show(Request $request, $key): JsonResponse;

    #[OA\Get(
        path: '/configurations',
        summary: 'Get all configurations for the current user',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Configuration'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/syncedSinceParam'),
            new OA\Parameter(ref: '#/components/parameters/noClientIdParam'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of all user configurations',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Configurations retrieved successfully'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'user_id', type: 'integer', example: 1),
                                    new OA\Property(property: 'key', type: 'string', example: 'theme_preference'),
                                    new OA\Property(property: 'value', type: 'object', example: '{"theme": "dark", "color": "#333333"}'),
                                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
                                ]
                            )
                        ),
                    ]
                )
            ),
        ]
    )]
    public function index(Request $request): JsonResponse;
}
