<?php

namespace Tests\Feature;

use App\Models\Category;
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
            'client_id' => '245cb3df-df3a-428b-a908-e5f74b8d58a4:245cb3df-df3a-428b-a908-e5f74b8d58a4',
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

    public function test_api_user_can_update_their_expense_categories_with_client_id()
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'expense',
            'name' => 'Expense Category',
            'description' => 'description',
        ]);
        $response->assertStatus(201);

        $deviceToken = '245cb3df-df3a-428b-a908-e5f74b8d58a4';
        $clientId = '245cb3df-df3a-428b-a908-e5f74b8d58a4';

        $id = $response->json('data.id');
        $response = $this->actingAs($user)->putJson('/api/v1/categories/'.$response->json('data.id'), [
            'name' => 'Updated Expense Category',
            'description' => 'Updated description',
            'client_id' => "$deviceToken:$clientId",
        ]);
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals('Updated Expense Category', $data['name']);
        $this->assertEquals('Updated description', $data['description']);

        $category = Category::find($id);
        $this->assertEquals($category->syncState->client_generated_id, $clientId);
    }

    public function test_api_user_cannot_create_category_with_invalid_client_id_format()
    {
        $user = User::factory()->create();

        // Test with client_id that has no colon
        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'expense',
            'name' => 'Category with invalid client id',
            'description' => 'test description',
            'client_id' => '245cb3df-df3a-428b-a908-e5f74b8d58a4',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('must be in the format', $response->json('errors.0'));

        // Test with client_id that has invalid UUID
        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'expense',
            'name' => 'Category with invalid UUID',
            'description' => 'test description',
            'client_id' => 'invalid-uuid:245cb3df-df3a-428b-a908-e5f74b8d58a4',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('not a valid UUID', $response->json('errors.0'));

        // Test with client_id that has more than one colon
        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'expense',
            'name' => 'Category with too many colons',
            'description' => 'test description',
            'client_id' => '245cb3df-df3a-428b-a908-e5f74b8d58a4:245cb3df-df3a-428b-a908-e5f74b8d58a4:extra',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('must be in the format', $response->json('errors.0'));
    }

    public function test_api_user_device_creation_with_client_id()
    {
        $user = User::factory()->create();
        $deviceToken = '245cb3df-df3a-428b-a908-e5f74b8d58a4';
        $clientId = '245cb3df-df3a-428b-a908-e5f74b8d58a3';

        // Create first category with client_id
        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'expense',
            'name' => 'First Category with client id',
            'description' => 'test description',
            'client_id' => "$deviceToken:$clientId",
        ]);

        $response->assertStatus(201);
        $firstCategory = Category::find($response->json('data.id'));

        // Verify device was created
        $this->assertDatabaseHas('devices', ['token' => $deviceToken, 'deviceable_id' => $user->id, 'deviceable_type' => 'App\Models\User']);
        $device = $user->devices()->where('token', $deviceToken)->first();
        $this->assertNotNull($device);

        // Verify sync state has correct device_id and client_generated_id
        $this->assertEquals($clientId, $firstCategory->syncState->client_generated_id);
        $this->assertEquals($device->id, $firstCategory->syncState->device_id);

        // Create second category with same device_id but different client_id
        $secondClientId = '245cb3df-df3a-428b-a908-e5f74b8d58a5';
        $response = $this->actingAs($user)->postJson('/api/v1/categories', [
            'type' => 'income',
            'name' => 'Second Category with same device',
            'description' => 'test description',
            'client_id' => "$deviceToken:$secondClientId",
        ]);

        $response->assertStatus(201);
        $secondCategory = Category::find($response->json('data.id'));

        // Verify same device was used
        $this->assertEquals(1, $user->devices()->where('token', $deviceToken)->count());

        // Verify second category has correct client_generated_id but same device_id
        $this->assertEquals($secondClientId, $secondCategory->syncState->client_generated_id);
        $this->assertEquals($device->id, $secondCategory->syncState->device_id);
    }
}
