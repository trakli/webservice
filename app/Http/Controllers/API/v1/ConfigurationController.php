<?php

namespace App\Http\Controllers\API\v1;

use App\Enums\ConfigValueType;
use App\Http\Controllers\API\ApiController;
use App\Models\UserConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class ConfigurationController extends ApiController
{
    #[OA\Post(
        path: '/configurations',
        summary: 'Add a new user configuration',
        security: [
            ['bearerAuth' => []],
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['key', 'value'],
                properties: [
                    new OA\Property(property: 'key', type: 'string', example: 'theme_preference'),
                    new OA\Property(property: 'value', type: 'object', example: '{"theme": "dark", "color": "#333333"}'),
                ]
            )
        ),
        tags: ['Configuration'],
        responses: [
            new OA\Response(response: 201, description: 'Configuration added successfully'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'key' => 'required',
            'value' => 'required',
            'type' => 'required|in:string,int,float,bool,array,json,date',
        ]);

        if ($validator->fails()) {
            return $this->failure('Validation failed.', 422, [$validator->errors()]);
        }

        $data = $validator->validated();
        $user = $request->user();
        $key = $data['key'];
        $formattedKey = $this->sanitizeKey($key);
        $configuration_type = ConfigValueType::from($data['type']);
        $value = $configuration_type->getValue($data['value']);

        UserConfiguration::setValue($formattedKey, $value, $user->id, $configuration_type);

        return $this->success(null, 'Configuration added successfully', 201);
    }

    private function sanitizeKey($key): mixed
    {
        $formattedKey = strtolower(preg_replace('/\s+/', '_', $key));
        $formattedKey = preg_replace('/[^a-z0-9_]/', '', $formattedKey);

        return $formattedKey;
    }

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
    public function update(Request $request, $key): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'value' => 'required',
            'type' => 'required|in:string,int,float,bool,array,json,date',
        ]);

        if ($validator->fails()) {
            return $this->failure('Validation failed.', 422, [$validator->errors()]);
        }

        $data = $validator->validated();
        $user = $request->user();

        $formattedKey = $this->sanitizeKey($key);

        // Check if the configuration exists
        $configuration = $user->configurations()->where('key', $formattedKey)->first();

        if (! $configuration) {
            return $this->failure('Configuration not found.', 404);
        }

        $configuration_type = ConfigValueType::from($data['type']);
        $value = $configuration_type->getValue($data['value']);

        // Update the configuration
        $configuration->update([
            'value' => $value,
        ]);

        return $this->success(null, 'Configuration updated successfully');
    }

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
    public function destroy(Request $request, $key)
    {
        $user = $request->user();

        $formattedKey = $this->sanitizeKey($key);

        // Check if the configuration exists
        $configuration = $user->configurations()->where('key', $formattedKey)->first();

        if (! $configuration) {
            return $this->failure('Configuration not found.', 404);
        }

        // Delete the configuration
        $configuration->delete();

        return $this->success(null, 'Configuration deleted successfully');
    }

    #[OA\Get(
        path: '/configurations',
        summary: 'Get all configurations for the current user',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Configuration'],
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
    public function index(Request $request)
    {
        $user = $request->user();
        $configurations = $user->configurations()->get();

        return $this->success($configurations, 'Configurations retrieved successfully');
    }
}
