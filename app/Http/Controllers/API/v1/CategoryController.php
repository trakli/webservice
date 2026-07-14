<?php

namespace App\Http\Controllers\API\v1;

use App\Contracts\Entitlements;
use App\Http\Controllers\API\ApiController;
use App\Http\Traits\ApiQueryable;
use App\Models\Category;
use App\Rules\Iso8601DateTime;
use App\Rules\ValidateClientId;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Category', description: 'Endpoints for managing transaction categories')]
class CategoryController extends ApiController
{
    use ApiQueryable;

    #[OA\Get(
        path: '/categories',
        summary: 'List all categories',
        tags: ['Category'],
        parameters: [
            new OA\Parameter(
                name: 'type',
                description: 'Type of the category (income or expense)',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', enum: ['income', 'expense'])
            ),
            new OA\Parameter(ref: '#/components/parameters/limitParam'),
            new OA\Parameter(ref: '#/components/parameters/syncedSinceParam'),
            new OA\Parameter(ref: '#/components/parameters/noClientIdParam'),

        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'last_sync', type: 'string', format: 'date-time'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Category')
                        ),
                    ],
                    type: 'object'
                )
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
        $categoriesQuery = $user->categories();

        if ($request->has('type') && $request->type == 'income') {
            $categoriesQuery->where('type', 'income');
        } elseif ($request->has('type') && $request->type == 'expense') {
            $categoriesQuery->where('type', 'expense');
        }

        try {
            $data = $this->applyApiQuery($request, $categoriesQuery);

            return $this->success($data);
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e->getMessage(), 422);
        }
    }

    #[OA\Put(
        path: '/categories/{id}',
        summary: 'Update a specific category',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'client_id',
                        description: 'Unique identifier for your local client',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'name',
                        description: 'Name of the category',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'description',
                        description: 'The description of the category',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'icon',
                        description: 'The icon of the category (file or icon string)',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'icon_type',
                        description: 'The type of the icon (icon or emoji or  image)',
                        type: 'string'
                    ),
                    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
                ]
            )
        ),
        tags: ['Category'],
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
    public function update(Request $request, int $categoryId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => ['nullable', 'string', new ValidateClientId()],
            'type' => 'sometimes|required|string|in:income,expense',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|string',
            'icon' => 'nullable',
            'icon_type' => 'required_with:icon|string|in:icon,image,emoji',
            'updated_at' => ['nullable', new Iso8601DateTime()],
        ]);

        if ($validator->fails()) {
            return $this->failure(__('Validation error'), 422, $validator->errors()->all());
        }

        $data = $validator->validated();
        $user = $request->user();

        if (isset($data['updated_at'])) {
            $data['updated_at'] = format_iso8601_to_sql($data['updated_at']);
        }

        $category = $user->categories()->find($categoryId);

        if (! $category) {
            return $this->failure(__('Category not found'), 404);
        }
        try {
            $this->checkUpdatedAt($category, $data);

            DB::transaction(function () use ($data, $request, &$category) {
                $this->updateModel($category, $data, $request);
            });

            $category->refresh();

            return $this->success($category, __('Category updated successfully'));
        } catch (ValidationException $e) {
            return $this->failure(__('Validation error'), 422, $e->errors());
        } catch (\Exception $e) {
            return $this->failure(__('Failed to update category'), 500, [$e->getMessage()]);
        }
    }

    #[OA\Post(
        path: '/categories',
        summary: 'Create a new category',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'name'],
                properties: [
                    new OA\Property(
                        property: 'client_id',
                        description: 'Unique identifier for your local client',
                        type: 'string',
                        format: 'string',
                        example: '245cb3df-df3a-428b-a908-e5f74b8d58a3:245cb3df-df3a-428b-a908-e5f74b8d58a4'
                    ),
                    new OA\Property(
                        property: 'type',
                        description: 'Type of the category',
                        type: 'string',
                        enum: ['income', 'expense']
                    ),
                    new OA\Property(
                        property: 'name',
                        description: 'Name of the category',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'description',
                        description: 'The description of the category',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'icon',
                        description: 'The icon of the category (file or icon string)',
                        type: 'string'
                    ),
                    new OA\Property(
                        property: 'icon_type',
                        description: 'The type of the icon (icon or emoji or  image)',
                        type: 'string'
                    ),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                ]
            )
        ),
        tags: ['Category'],
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
            'client_id' => ['nullable', 'string', new ValidateClientId()],
            'type' => 'required|string|in:income,expense',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'icon' => 'nullable',
            'icon_type' => 'required_with:icon|string|in:icon,image,emoji',
            'created_at' => ['nullable', new Iso8601DateTime()],
        ]);

        if ($validator->fails()) {
            return $this->failure(__('Validation error'), 422, $validator->errors()->all());
        }

        $data = $validator->validated();
        $user = $request->user();
        $data['user_id'] = $user->id;
        $category_exists = $user->categories()->where('name', $data['name'])->where('user_id', $user->id)->first();
        if ($category_exists) {
            return $this->failure(__('Category already exists'), 400);
        }

        $categoryLimit = app(Entitlements::class)->limit($user, 'max_categories');
        if ($categoryLimit !== null && $user->categories()->count() >= $categoryLimit) {
            return $this->failure(__('You have reached the maximum number of categories allowed.'), 403);
        }

        try {
            $category = DB::transaction(function () use ($data, $request, $user) {
                /** @var Category $category */
                $category = $user->categories()->create($data);

                if (isset($request['client_id'])) {
                    $category->setClientGeneratedId($request['client_id'], $user);
                }
                $category->markAsSynced();

                FileService::updateIcon($category, $data, $request);

                return $category;
            });

            $category->refresh();

            return $this->success($category, __('Category created successfully'), 201);
        } catch (ValidationException $e) {
            return $this->failure(__('Validation error'), 422, $e->errors());
        } catch (\Exception $e) {
            return $this->failure(__('Failed to create category'), 500, [$e->getMessage()]);
        }
    }

    #[
        OA\Get(
            path: '/categories/{id}',
            summary: 'Get a specific category',
            tags: ['Category'],
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
    public function show(Request $request, int $categoryId): JsonResponse
    {
        $user = $request->user();
        $category = $user->categories()->find($categoryId);

        if (! $category) {
            return $this->failure(__('Category not found'), 404);
        }

        return $this->success($category);
    }

    #[OA\Delete(
        path: '/categories/{id}',
        summary: 'Delete a specific category',
        tags: ['Category'],
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
    public function destroy(Request $request, int $categoryId): JsonResponse
    {
        $user = $request->user();
        $category = $user->categories()->find($categoryId);

        if (! $category) {
            return $this->failure(__('Category not found'), 404);
        }

        $category->delete();

        return $this->success(null, __('Category deleted successfully'), 204);
    }

    #[OA\Post(
        path: '/categories/seed-defaults',
        description: 'Creates a set of predefined income and expense categories. Skips categories that already exist.',
        summary: 'Create default categories for the user',
        tags: ['Category'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Default categories created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'created', type: 'integer'),
                                new OA\Property(property: 'skipped', type: 'integer'),
                                new OA\Property(
                                    property: 'categories',
                                    type: 'array',
                                    items: new OA\Items(ref: '#/components/schemas/Category')
                                ),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 500,
                description: 'Internal server error'
            ),
        ]
    )]
    public function seedDefaults(Request $request): JsonResponse
    {
        $user = $request->user();

        $defaultCategories = [
            // Income categories
            ['type' => 'income', 'name' => __('Salary'), 'description' => __('Regular employment income')],
            ['type' => 'income', 'name' => __('Freelance'), 'description' => __('Contract/gig work')],
            ['type' => 'income', 'name' => __('Investments'), 'description' => __('Dividends, interest, capital gains')],
            ['type' => 'income', 'name' => __('Gifts'), 'description' => __('Money received as gifts')],
            ['type' => 'income', 'name' => __('Refunds'), 'description' => __('Returns and reimbursements')],
            ['type' => 'income', 'name' => __('Other Income'), 'description' => __('Miscellaneous income')],
            // Expense categories
            ['type' => 'expense', 'name' => __('Food & Dining'), 'description' => __('Groceries, restaurants, takeout')],
            ['type' => 'expense', 'name' => __('Transportation'), 'description' => __('Fuel, public transit, ride-share, parking')],
            ['type' => 'expense', 'name' => __('Housing'), 'description' => __('Rent, mortgage, repairs, maintenance')],
            ['type' => 'expense', 'name' => __('Utilities'), 'description' => __('Electric, water, gas, internet, phone')],
            ['type' => 'expense', 'name' => __('Healthcare'), 'description' => __('Medical, dental, pharmacy, insurance')],
            ['type' => 'expense', 'name' => __('Entertainment'), 'description' => __('Movies, games, streaming, hobbies')],
            ['type' => 'expense', 'name' => __('Shopping'), 'description' => __('Clothing, electronics, household items')],
            ['type' => 'expense', 'name' => __('Personal Care'), 'description' => __('Haircuts, cosmetics, gym')],
            ['type' => 'expense', 'name' => __('Education'), 'description' => __('Courses, books, tuition')],
            ['type' => 'expense', 'name' => __('Subscriptions'), 'description' => __('Recurring services, memberships')],
            ['type' => 'expense', 'name' => __('Insurance'), 'description' => __('Auto, home, life (non-health)')],
            ['type' => 'expense', 'name' => __('Savings'), 'description' => __('Transfers to savings/investments')],
            ['type' => 'expense', 'name' => __('Gifts & Donations'), 'description' => __('Charity, presents for others')],
            ['type' => 'expense', 'name' => __('Travel'), 'description' => __('Vacations, hotels, flights')],
            ['type' => 'expense', 'name' => __('Other Expenses'), 'description' => __('Miscellaneous spending')],
        ];

        $existingNames = $user->categories()->pluck('name')->map(fn ($n) => strtolower($n))->toArray();

        $created = [];
        $skipped = 0;

        try {
            DB::transaction(function () use ($user, $defaultCategories, $existingNames, &$created, &$skipped) {
                foreach ($defaultCategories as $categoryData) {
                    if (in_array(strtolower($categoryData['name']), $existingNames)) {
                        $skipped++;

                        continue;
                    }

                    $category = $user->categories()->create($categoryData);
                    $category->markAsSynced();
                    $created[] = $category;
                }
            });

            return $this->success([
                'created' => count($created),
                'skipped' => $skipped,
                'categories' => $created,
            ], __('Default categories created successfully'), 201);
        } catch (\Exception $e) {
            return $this->failure(__('Failed to create default categories'), 500, [$e->getMessage()]);
        }
    }
}
