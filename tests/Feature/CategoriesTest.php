<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CategoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_user_can_create_expense_categories()
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'expense',
            'name' => 'Expense Category',
            'description' => 'description',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'name',
                    'description',
                    'user_id',
                    'client_generated_id',
                ],
                'message',
            ]);

        $this->assertDatabaseHas('categories', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_create_a_category_with_an_emoji()
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'expense',
            'name' => 'Expense Category',
            'description' => 'description',
            'icon' => '👆',
            'icon_type' => 'emoji',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'name',
                    'description',
                    'user_id',
                    'client_generated_id',
                    'icon' => [
                        'type',
                        'content',
                    ],
                ],
                'message',
            ]);

        $this->assertEquals('👆', $response->json('data.icon.content'));
        $this->assertEquals('emoji', $response->json('data.icon.type'));

        $this->assertDatabaseHas('categories', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_update_a_category_image()
    {
        $user = User::factory()->create();
        $imageFile = UploadedFile::fake()->createWithContent(
            'image.png',
            'data:image/png;base64,someEncodedImagePNGImageHereYII='
        );

        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'expense',
            'name' => 'Expense Category',
            'description' => 'description',
            'image' => '👆',
            'image_type' => 'emoji',
        ]);

        $response->assertStatus(201);
        $id = $response->json('data.id');
        $response = $this->actingAs($user)->putJson('/api/v1/categories/'.$id, [
            'type' => 'expense',
            'name' => 'Expense Category',
            'description' => 'description',
            'icon' => $imageFile,
            'icon_type' => 'image',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'name',
                    'description',
                    'user_id',
                    'client_generated_id',
                    'icon' => [
                        'type',
                        'content',
                    ],
                ],
                'message',
            ]);

        $this->assertNotNull($response->json('data.icon.image'));
        $this->assertEquals('png', $response->json('data.icon.image.type'));

        $this->assertDatabaseHas('categories', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_update_a_category_emoji()
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'expense',
            'name' => 'Expense Category',
            'description' => 'description',
            'image' => '👆',
            'image_type' => 'emoji',
        ]);

        $response->assertStatus(201);
        $id = $response->json('data.id');
        $response = $this->actingAs($user)->putJson('/api/v1/categories/'.$id, [
            'type' => 'expense',
            'name' => 'Expense Category',
            'description' => 'description',
            'icon' => '✅',
            'icon_type' => 'emoji',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'name',
                    'description',
                    'user_id',
                    'client_generated_id',
                    'icon' => [
                        'type',
                        'content',
                    ],
                ],
                'message',
            ]);

        $this->assertEquals('✅', $response->json('data.icon.content'));
        $this->assertEquals('emoji', $response->json('data.icon.type'));

        $this->assertDatabaseHas('categories', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_not_create_a_category_with_a_non_image_file()
    {
        $user = User::factory()->create();

        // Create a fake CSV file
        $csvFile = UploadedFile::fake()->createWithContent(
            'test.csv',
            $content ?? "amount,currency,type,party,wallet,category,description,date\n".
        "100,USD,expense,John Doe,Wallet1,Food,Lunch,2023-01-01\n".
        '200,USD,income,Jane Doe,Wallet2,Salary,Monthly Salary,2023-01-02'
        );
        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'expense',
            'name' => 'Expense Category',
            'description' => 'description',
            'icon' => $csvFile,
            'icon_type' => 'image',
        ]);

        $response->assertStatus(422);
    }

    public function test_api_user_can_create_a_category_with_an_image()
    {
        $user = User::factory()->create();
        // Create a fake image file
        $imageFile = UploadedFile::fake()->createWithContent(
            'image.png',
            'data:image/png;base64,someEncodedImagePNGImageHereYII='
        );
        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'expense',
            'name' => 'Expense Category',
            'description' => 'description',
            'icon' => $imageFile,
            'icon_type' => 'image',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'name',
                    'description',
                    'user_id',
                    'client_generated_id',
                    'icon' => [
                        'type',
                        'content',
                    ],
                ],
                'message',
            ]);

        $this->assertEquals('image', $response->json('data.icon.type'));

        $this->assertDatabaseHas('categories', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_create_expense_categories_with_client_id()
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'expense',
            'name' => 'Expense Category (with client id)',
            'description' => 'description',
            'client_id' => '123e4567-e89b-12d3-a456-426614174000',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'name',
                    'description',
                    'user_id',
                ],
                'message',
            ]);

        $this->assertDatabaseHas('categories', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_get_their_categories()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'expense',
            'name' => 'Expense Category',
            'description' => 'description',
        ]);

        $response->assertStatus(201);

        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'income',
            'name' => 'Income Category',
            'description' => 'description',
        ]);

        $response->assertStatus(201);

        $response = $this->actingAs($user)->get('/api/v1/categories?type=expense');

        $response->assertStatus(200);

        $response = $this->actingAs($user)->get('/api/v1/categories?type=income');

        $response->assertStatus(200);
    }

    public function test_api_user_can_update_their_expense_categories()
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'expense',
            'name' => 'Expense Category',
            'description' => 'description',
        ]);
        $response->assertStatus(201);

        $response = $this->actingAs($user)->putJson('/api/v1/categories/'.$response->json('data.id'), [
            'name' => 'Updated Expense Category',
            'description' => 'Updated description',
        ]);
        $response->assertStatus(200);

        $this->assertDatabaseHas('categories', ['name' => $response->json('data.name'), 'description' => $response->json('data.description')]);
    }

    public function test_api_user_can_update_their_income_categories()
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'income',
            'name' => 'Income Category',
            'description' => 'description',
        ]);

        $response->assertStatus(201);

        $response = $this->actingAs($user)->putJson('/api/v1/categories/'.$response->json('data.id'), [
            'name' => 'Updated Income Category',
            'description' => 'Updated description',
        ]);

        $this->assertDatabaseHas('categories', ['name' => $response->json('data.name'), 'description' => $response->json('data.description')]);
    }

    public function test_api_user_can_delete_their_expense_categories()
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'expense',
            'name' => 'Expense Category',
            'description' => 'description',
        ]);

        $response->assertStatus(201);

        $response = $this->actingAs($user)->delete('/api/v1/categories/'.$response->json('data.id').'?type=expense');

        $response->assertStatus(204);
    }

    public function test_api_user_can_delete_their_income_categories()
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'income',
            'name' => 'Income Category',
            'description' => 'description',
        ]);

        $response->assertStatus(201);

        $response = $this->actingAs($user)->delete('/api/v1/categories/'.$response->json('data.id').'?type=income');

        $response->assertStatus(204);
    }

    public function test_api_user_can_create_income_categories()
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'income',
            'name' => 'Income Category',
            'description' => 'description',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'name',
                    'description',
                    'user_id',
                ],
                'message',
            ]);

        $this->assertDatabaseHas('categories', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_not_create_categories_with_missing_information()
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/v1/categories', []);

        $response->assertStatus(422);
    }

    public function test_api_user_cannot_create_category_with_invalid_type()
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'invalid',
            'name' => 'Test Category',
            'description' => 'description',
        ]);

        $response->assertStatus(422);
    }

    public function test_unauthorized_user_cannot_access_categories()
    {
        $response = $this->getJson('/api/v1/categories');
        $response->assertStatus(401);
    }

    public function test_api_user_cannot_create_duplicate_category_names_of_same_type()
    {
        $user = User::factory()->create();

        // Create first category
        $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'expense',
            'name' => 'Test Category',
            'description' => 'description',
        ]);

        // Attempt to create duplicate
        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'expense',
            'name' => 'Test Category',
            'description' => 'description',
        ]);

        $response->assertStatus(400);
    }
}
