<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileControllerTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    private $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create();

        $this->wallet = $this->user->wallets()->create([
            'name' => 'Wallet',
            'balance' => 1000,
        ]);
    }

    public function test_file_response_includes_link_attribute()
    {
        $imageFile = UploadedFile::fake()->image('receipt.png');

        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
            'files' => [$imageFile],
        ]);

        $response->assertStatus(201);

        $files = $response->json('data.files');
        $this->assertCount(1, $files);
        $this->assertArrayHasKey('link', $files[0]);
        $this->assertStringContainsString('/api/v1/files/', $files[0]['link']);
    }

    public function test_authenticated_user_can_access_their_file()
    {
        $imageFile = UploadedFile::fake()->image('receipt.png');

        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
            'files' => [$imageFile],
        ]);

        $response->assertStatus(201);

        $fileId = $response->json('data.files.0.id');
        $filePath = $response->json('data.files.0.path');

        $this->assertNotNull($fileId, 'File ID should not be null');
        $this->assertNotNull($filePath, 'File path should not be null');

        Storage::assertExists($filePath);

        $response = $this->actingAs($this->user)->get("/api/v1/files/{$fileId}");

        $response->assertStatus(200);
    }

    public function test_unauthenticated_user_cannot_access_files()
    {
        $imageFile = UploadedFile::fake()->image('receipt.png');

        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
            'files' => [$imageFile],
        ]);

        $response->assertStatus(201);

        $fileId = $response->json('data.files.0.id');

        auth()->forgetGuards();

        $response = $this->get("/api/v1/files/{$fileId}");

        $response->assertStatus(401);
    }

    public function test_user_cannot_access_another_users_file()
    {
        $anotherUser = User::factory()->create();
        $anotherWallet = $anotherUser->wallets()->create([
            'name' => 'Another Wallet',
            'balance' => 500,
        ]);

        $imageFile = UploadedFile::fake()->image('receipt.png');

        $response = $this->actingAs($anotherUser)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $anotherWallet->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
            'files' => [$imageFile],
        ]);

        $response->assertStatus(201);

        $fileId = $response->json('data.files.0.id');

        $response = $this->actingAs($this->user)->get("/api/v1/files/{$fileId}");

        $response->assertStatus(403);
    }

    public function test_returns_404_for_non_existent_file()
    {
        $response = $this->actingAs($this->user)->get('/api/v1/files/99999');

        $response->assertStatus(404);
    }

    public function test_returns_404_when_file_missing_from_storage()
    {
        $imageFile = UploadedFile::fake()->image('receipt.png');

        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
            'files' => [$imageFile],
        ]);

        $response->assertStatus(201);

        $fileId = $response->json('data.files.0.id');
        $filePath = $response->json('data.files.0.path');

        Storage::delete($filePath);

        $response = $this->actingAs($this->user)->get("/api/v1/files/{$fileId}");

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'File not found on storage',
            ]);
    }
}
