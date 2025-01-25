<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Models\ExpenseCategory;
use App\Models\IncomeCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Transaction Categories', description: 'Endpoints for managing transaction categories')]
class TransactionCategoryController extends ApiController
{
    #[OA\Get(
        path: '/categories',
        summary: 'List all categories',
        tags: ['Transaction Categories'],
        parameters: [
            new OA\Parameter(
                name: 'type',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', enum: ['income', 'expense']),
                description: 'Type of the category (income or expense)'
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/TransactionCategory'))
            ),
            new OA\Response(
                response: 500,
                description: 'Internal server error'
            ),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type');

        if ($type === 'income') {
            $categories = IncomeCategory::paginate(20);
        } elseif ($type === 'expense') {
            $categories = ExpenseCategory::paginate(20);
        } else {
            return $this->failure('Invalid category type', 400);
        }

        return $this->success($categories);
    }

    #[OA\Post(
        path: '/categories',
        summary: 'Create a new category',
        tags: ['Transaction Categories'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'name'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['income', 'expense'], description: 'Type of the category'),
                    new OA\Property(property: 'name', type: 'string', description: 'Name of the category'),
                    new OA\Property(property: 'description', type: 'string', description: 'The description of the category'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Category created successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/TransactionCategory')
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
            'type' => 'required|string|in:income,expense',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->failure('Validation error', 400, $validator->errors()->all());
        }

        $data = $validator->validated();
        $category = null;
        $user = $request->user();
        $data['user_id'] = $user->id;

        if ($data['type'] === 'income') {
            unset($data['type']);
            $category = $user->incomeCategories()->firstOrcreate($data);
        } elseif ($data['type'] === 'expense') {
            unset($data['type']);
            $category = $user->expenseCategories()->firstOrCreate($data);
        }

        return $this->success($category, 'Category created successfully', 201);
    }

    #[OA\Get(
        path: '/categories/{id}',
        summary: 'Get a specific category',
        tags: ['Transaction Categories'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'ID of the category'
            ),
            new OA\Parameter(
                name: 'type',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', enum: ['income', 'expense']),
                description: 'Type of the category (income or expense)'
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(ref: '#/components/schemas/TransactionCategory')
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
        $type = $request->query('type');

        if ($type === 'income') {
            $category = IncomeCategory::find($id);
        } elseif ($type === 'expense') {
            $category = ExpenseCategory::find($id);
        } else {
            return $this->failure('Invalid category type', 400);
        }

        if (! $category) {
            return $this->failure('Category not found', 404);
        }

        return $this->success($category);
    }

    #[OA\Put(
        path: '/categories/{id}',
        summary: 'Update a specific category',
        tags: ['Transaction Categories'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'ID of the category'
            ),
            new OA\Parameter(
                name: 'type',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', enum: ['income', 'expense']),
                description: 'Type of the category (income or expense)'
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
                content: new OA\JsonContent(ref: '#/components/schemas/TransactionCategory')
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
        $category = null;
        $user = $request->user();

        if ($data['type'] === 'income') {
            $category = $user->incomeCategories()->findOrFail($id);
        } elseif ($data['type'] === 'expense') {
            $category = $user->expenseCategories()->findOrFail($id);
        } else {
            return $this->failure('Invalid category type', 400);
        }

        if (! $category) {
            return $this->failure('Category not found', 404);
        }

        unset($data['type']);
        $category->update($data);

        return $this->success($category, 'Category updated successfully');
    }

    #[OA\Delete(
        path: '/categories/{id}',
        summary: 'Delete a specific category',
        tags: ['Transaction Categories'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'ID of the category'
            ),
            new OA\Parameter(
                name: 'type',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', enum: ['income', 'expense']),
                description: 'Type of the category (income or expense)'
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
        $type = $request->query('type');
        $user = $request->user();
        if ($type === 'income') {
            $category = $user->incomeCategories()->findOrFail($id);
        } elseif ($type === 'expense') {
            $category = $user->expenseCategories()->findOrFail($id);
        } else {
            return $this->failure('Invalid category type', 400);
        }

        if (! $category) {
            return $this->failure('Category not found', 404);
        }

        $category->delete();

        return $this->success(null, 'Category deleted successfully', 204);
    }
}
