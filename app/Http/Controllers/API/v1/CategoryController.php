<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Categories', description: 'Endpoints for managing transaction categories')]
class CategoryController extends ApiController
{
    #[OA\Get(
        path: '/categories',
        summary: 'List all categories',
        tags: ['Categories'],
        parameters: [
            new OA\Parameter(
                name: 'type',
                description: 'Type of the category (income or expense)',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', enum: ['income', 'expense'])
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Category'))
            ),
            new OA\Response(
                response: 500,
                description: 'Internal server error'
            ),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $categories = $user->categories();
        if ($request->has('type') && $request->type == 'income') {
            $categories = $categories->where('type', 'income');
        } elseif ($request->has('type') && $request->type == 'expense') {
            $categories = $categories->where('type', 'expense');
        }
        $categories = $categories->paginate(20);

        return $this->success($categories);
    }

    #[OA\Post(
        path: '/categories',
        summary: 'Create a new category',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'name'],
                properties: [
                    new OA\Property(property: 'client_id', type: 'string', format: 'uuid',
                        description: 'Unique identifier for your local client'),
                    new OA\Property(property: 'type', description: 'Type of the category', type: 'string', enum: ['income', 'expense']),
                    new OA\Property(property: 'name', description: 'Name of the category', type: 'string'),
                    new OA\Property(property: 'description', description: 'The description of the category', type: 'string'),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                ]
            )
        ),
        tags: ['Categories'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Category created successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/Category')
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            ),
            new OA\Response(
                response: 500,
                description: 'Internal server error'
            ),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'nullable|uuid',
            'type' => 'required|string|in:income,expense',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'created_at' => ['nullable', 'date_format:Y-m-d H:i:s'],
        ]);

        if ($validator->fails()) {
            return $this->failure('Validation error', 400, $validator->errors()->all());
        }

        $data = $validator->validated();
        $user = $request->user();
        $data['user_id'] = $user->id;
        $category_exists = $user->categories()->where('name', $data['name'])->where('user_id', $user->id)->first();
        if ($category_exists) {
            return $this->failure('Category already exists', 400);
        }
        $category = $user->categories()->firstOrCreate($data);

        return $this->success($category, 'Category created successfully', 201);
    }

    #[OA\Get(
        path: '/categories/{id}',
        summary: 'Get a specific category',
        tags: ['Categories'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'ID of the category',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(ref: '#/components/schemas/Category')
            ),
            new OA\Response(
                response: 404,
                description: 'Category not found'
            ),
            new OA\Response(
                response: 500,
                description: 'Internal server error'
            ),
        ]
    )]
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $category = $user->categories()->find($id);

        if (! $category) {
            return $this->failure('Category not found', 404);
        }

        return $this->success($category);
    }

    #[OA\Put(
        path: '/categories/{id}',
        summary: 'Update a specific category',
        tags: ['Categories'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'ID of the category'
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', description: 'Name of the category'),
                    new OA\Property(property: 'description', type: 'string', description: 'The description of the category'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Category updated successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/Category')
            ),
            new OA\Response(
                response: 404,
                description: 'Category not found'
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            ),
            new OA\Response(
                response: 500,
                description: 'Internal server error'
            ),
        ]
    )]
    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|string|in:income,expense',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->failure('Validation error', 400, $validator->errors()->all());
        }

        $data = $validator->validated();
        $user = $request->user();

        $category = $user->categories()->find($id);

        if (! $category) {
            return $this->failure('Category not found', 404);
        }

        $category->update($data);

        return $this->success($category, 'Category updated successfully');
    }

    #[OA\Delete(
        path: '/categories/{id}',
        summary: 'Delete a specific category',
        tags: ['Categories'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'ID of the category'
            ),
        ],
        responses: [
            new OA\Response(
                response: 204,
                description: 'Category deleted successfully'
            ),
            new OA\Response(
                response: 404,
                description: 'Category not found'
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid category type'
            ),
            new OA\Response(
                response: 500,
                description: 'Internal server error'
            ),
        ]
    )]
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $category = $user->categories()->find($id);

        if (! $category) {
            return $this->failure('Category not found', 404);
        }

        $category->delete();

        return $this->success(null, 'Category deleted successfully', 204);
    }
}
